<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * Financeiro da plataforma (área admin): receita pelas cobranças da Stripe (com a taxa de cada uma), MRR pelas
 * assinaturas ativas, custos automáticos (taxas da Stripe, OpenAI) + custos fixos informados, e o lucro por mês.
 * A Stripe (API 2025 "basil") não liga mais fatura e cobrança, então a receita vem das cobranças.
 */
class PlatformFinance
{
    /** Situações que contam no MRR (em teste fica de fora: ainda não pagou). */
    private const MRR_STATUSES = ['active', 'past_due'];

    /* ---------- Sincronização ---------- */

    /** Cobranças pagas desde a data (cria ou atualiza — reembolso e taxa mudam depois). Devolve quantas. */
    public function syncPayments(Carbon $since): int
    {
        $companies = Company::whereNotNull('stripe_id')->pluck('id', 'stripe_id');
        $count = 0;

        $charges = Cashier::stripe()->charges->all([
            'created' => ['gte' => $since->getTimestamp()],
            'limit' => 100,
            'expand' => ['data.balance_transaction'],
        ]);

        foreach ($charges->autoPagingIterator() as $charge) {
            if ($this->storeCharge($charge, $companies)) {
                $count++;
            }
        }

        return $count;
    }

    /** Uma cobrança (webhook charge.succeeded/refunded): busca com a taxa e grava. */
    public function syncCharge(string $chargeId): void
    {
        $charge = Cashier::stripe()->charges->retrieve($chargeId, ['expand' => ['balance_transaction']]);
        $this->storeCharge($charge, Company::whereNotNull('stripe_id')->pluck('id', 'stripe_id'));
    }

    private function storeCharge(object $charge, Collection $companies): bool
    {
        if ($charge->status !== 'succeeded' || ! $charge->paid) {
            return false;
        }

        $toMoney = fn ($cents) => round(((int) $cents) / 100, 2);
        $balance = is_object($charge->balance_transaction ?? null) ? $charge->balance_transaction : null;

        DB::table('platform_payments')->updateOrInsert(['stripe_charge_id' => $charge->id], [
            'company_id' => $charge->customer ? ($companies[$charge->customer] ?? null) : null,
            'stripe_customer_id' => $charge->customer,
            'amount' => $toMoney($charge->amount),
            'amount_refunded' => $toMoney($charge->amount_refunded),
            'fee' => $balance ? $toMoney($balance->fee) : null,
            'currency' => strtoupper($charge->currency),
            'description' => mb_substr((string) $charge->description, 0, 255) ?: null,
            'receipt_url' => $charge->receipt_url,
            'paid_at' => Carbon::createFromTimestamp($charge->created),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return true;
    }

    /** Tarifas avulsas da Stripe (cobradas fora das cobranças, ex: Billing) por mês. */
    public function syncStripeFees(Carbon $from): void
    {
        foreach ($this->months($from) as $month) {
            [$start, $end] = $this->monthRange($month);
            $total = 0;
            $list = Cashier::stripe()->balanceTransactions->all(['type' => 'stripe_fee', 'created' => ['gte' => $start->getTimestamp(), 'lt' => $end->getTimestamp()], 'limit' => 100]);
            foreach ($list->autoPagingIterator() as $tx) {
                $total += -((int) $tx->amount) + (int) $tx->fee;
            }
            $this->storeMonth('stripe_billing', $month, round($total / 100, 2), 'BRL');
        }
    }

    /** Custo da OpenAI por mês (Costs API, em USD) — precisa da chave de administrador. */
    public function syncOpenAi(Carbon $from): bool
    {
        $key = config('services.openai.admin_key');
        if (! $key) {
            return false;
        }

        foreach ($this->months($from) as $month) {
            [$start, $end] = $this->monthRange($month);
            $total = 0.0;
            $page = null;
            do {
                $response = Http::withToken($key)->timeout(30)->get('https://api.openai.com/v1/organization/costs', array_filter([
                    'start_time' => $start->getTimestamp(),
                    'end_time' => min($end->getTimestamp(), now()->getTimestamp()),
                    'bucket_width' => '1d',
                    'limit' => 31,
                    'page' => $page,
                ]))->throw()->json();
                foreach ($response['data'] ?? [] as $bucket) {
                    foreach ($bucket['results'] ?? [] as $result) {
                        $total += (float) ($result['amount']['value'] ?? 0);
                    }
                }
                $page = ($response['has_more'] ?? false) ? ($response['next_page'] ?? null) : null;
            } while ($page);

            $this->storeMonth('openai', $month, round($total, 2), 'USD');
        }

        return true;
    }

    /** Tudo de uma vez (comando diário e botão "Atualizar agora"). Erros de uma fonte não param as outras. */
    public function syncAll(bool $full = false): array
    {
        $result = [];
        $paymentsSince = $full ? now()->subMonths(13)->startOfMonth() : now()->subDays(45);
        $costsFrom = $full ? now()->subMonths(12)->startOfMonth() : now()->subMonth()->startOfMonth();

        foreach ([
            'payments' => fn () => $this->syncPayments($paymentsSince),
            'stripe_fees' => fn () => $this->syncStripeFees($costsFrom),
            'openai' => fn () => $this->syncOpenAi($costsFrom),
            // Sem cotação nenhuma (nem guardada) é falha: antes o "?? true" abaixo mostrava "ok" mesmo assim
            'usd_rate' => fn () => $this->usdRate(fresh: true) ?? throw new \RuntimeException('nenhuma fonte de cotação respondeu (veja "Cotação do dólar indisponível" no log)'),
            'prices' => fn () => $this->refreshPrices(),
        ] as $name => $job) {
            try {
                $result[$name] = $job() ?? true;
            } catch (Throwable $e) {
                Log::warning("Financeiro da plataforma: falha ao sincronizar {$name}: {$e->getMessage()}");
                $result[$name] = 'erro: '.mb_substr($e->getMessage(), 0, 160);
            }
        }
        Cache::forever('platform_finance_synced_at', now()->toIso8601String());

        return $result;
    }

    /**
     * Cotação do dólar (AwesomeAPI e, se ela falhar, Frankfurter), guardada por 12h; sem resposta, usa a última que
     * deu certo.
     * $network = false (tela do financeiro): só o que está guardado — a tela nunca espera serviço de fora.
     */
    public function usdRate(bool $fresh = false, bool $network = true): ?float
    {
        if (! $fresh && ($rate = Cache::get('usd_brl_rate'))) {
            return (float) $rate;
        }
        if (! $network) {
            return isset(Cache::get('usd_brl_rate_last')['rate']) ? (float) Cache::get('usd_brl_rate_last')['rate'] : null;
        }

        // Fontes na ordem: se a primeira não responder (ou bloquear o servidor), tenta a seguinte
        $sources = [
            'AwesomeAPI' => ['https://economia.awesomeapi.com.br/json/last/USD-BRL', 'USDBRL.bid'],
            'Frankfurter' => ['https://api.frankfurter.dev/v1/latest?base=USD&symbols=BRL', 'rates.BRL'],
        ];
        foreach ($sources as $name => [$url, $path]) {
            try {
                $bid = (float) Http::timeout(10)->get($url)->throw()->json($path);
                if ($bid > 0) {
                    Cache::put('usd_brl_rate', $bid, now()->addHours(12));
                    Cache::forever('usd_brl_rate_last', ['rate' => $bid, 'at' => now()->toIso8601String()]);

                    return $bid;
                }
                Log::info("Cotação do dólar ({$name}): resposta sem valor.");
            } catch (Throwable $e) {
                Log::info("Cotação do dólar indisponível ({$name}): ".$e->getMessage());
            }
        }

        return isset(Cache::get('usd_brl_rate_last')['rate']) ? (float) Cache::get('usd_brl_rate_last')['rate'] : null;
    }

    /* ---------- Números ---------- */

    /**
     * Valor mensal dos preços da Stripe (anual vira 1/12), guardado sem prazo e renovado pela sincronização (comando
     * diário, "Atualizar agora" ou em segundo plano quando a tela encontra um preço que ainda não conhece).
     * Só guarda o que a Stripe respondeu — falha não vira "R$ 0" guardado.
     */
    public function refreshPrices(): int
    {
        $ids = DB::table('subscriptions')->where('type', 'default')->distinct()->pluck('stripe_price')
            ->merge([config('services.stripe.price_essential'), config('services.stripe.price_pro')])
            ->filter()->unique();
        $done = 0;
        foreach ($ids as $priceId) {
            try {
                $price = Cashier::stripe()->prices->retrieve($priceId);
            } catch (Throwable $e) {
                Log::info("Financeiro: preço {$priceId} não lido na Stripe: {$e->getMessage()}");

                continue;
            }
            $amount = ((int) $price->unit_amount) / 100;
            $interval = $price->recurring->interval ?? 'month';
            $count = max(1, (int) ($price->recurring->interval_count ?? 1));
            Cache::forever("stripe_price_monthly:{$priceId}", round(match ($interval) {
                'year' => $amount / (12 * $count),
                'week' => $amount * 52 / 12 / $count,
                'day' => $amount * 365 / 12 / $count,
                default => $amount / $count,
            }, 2));
            $done++;
        }

        return $done;
    }

    /** MRR só com os preços já guardados; os que faltam vão em missing_prices (a tela pede a atualização). */
    public function mrr(): array
    {
        $subs = DB::table('subscriptions')->where('type', 'default')->whereIn('stripe_status', self::MRR_STATUSES)->get(['stripe_price', 'quantity']);
        $missing = [];
        $mrr = $subs->sum(function ($s) use (&$missing) {
            $monthly = Cache::get("stripe_price_monthly:{$s->stripe_price}");
            if ($monthly === null) {
                $missing[] = (string) $s->stripe_price;

                return 0;
            }

            return (float) $monthly * max(1, (int) $s->quantity);
        });

        return ['mrr' => round($mrr, 2), 'paying' => $subs->count(), 'missing_prices' => array_values(array_unique($missing))];
    }

    /** Cancelamentos no mês ÷ assinaturas ativas no começo do mês. */
    public function churn(string $month): array
    {
        [$start, $end] = $this->monthRange($month);
        $base = DB::table('subscriptions')->where('type', 'default')->where('created_at', '<', $start)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $start))->count();
        $canceled = DB::table('subscriptions')->where('type', 'default')->where('stripe_status', 'canceled')
            ->whereBetween('ends_at', [$start, $end])->count();

        return ['canceled' => $canceled, 'base' => $base, 'rate' => $base > 0 ? round($canceled / $base * 100, 1) : null];
    }

    /** Receita, custos e lucro dos últimos 12 meses (o atual por último). */
    public function monthly(): array
    {
        $rate = $this->usdRate(network: false);
        $from = now()->subMonths(11)->startOfMonth();
        $payments = DB::table('platform_payments')->where('paid_at', '>=', $from)
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') AS month, SUM(amount) AS revenue, SUM(amount_refunded) AS refunds, SUM(COALESCE(fee, 0)) AS fees")
            ->groupBy('month')->get()->keyBy('month');
        $apiCosts = DB::table('platform_cost_months')->where('month', '>=', $from->format('Y-m'))->get()->groupBy('month');
        $fixed = DB::table('platform_costs')->get();

        return collect($this->months($from))->map(function (string $month) use ($payments, $apiCosts, $fixed, $rate) {
            [$start, $end] = $this->monthRange($month);
            $toBrl = fn (float $amount, string $currency) => $currency === 'USD' ? ($rate ? $amount * $rate : 0.0) : $amount;
            $api = $apiCosts->get($month, collect());
            $openAiUsd = (float) ($api->firstWhere('provider', 'openai')->amount ?? 0);
            $stripeBilling = (float) ($api->firstWhere('provider', 'stripe_billing')->amount ?? 0);
            $fixedTotal = $fixed->filter(fn ($c) => (! $c->starts_on || $c->starts_on <= $end->toDateString()) && (! $c->ends_on || $c->ends_on >= $start->toDateString()))
                ->sum(fn ($c) => $toBrl((float) $c->amount / ($c->frequency === 'yearly' ? 12 : 1), $c->currency));

            $revenue = (float) ($payments[$month]->revenue ?? 0);
            $refunds = (float) ($payments[$month]->refunds ?? 0);
            $stripeFees = (float) ($payments[$month]->fees ?? 0) + $stripeBilling;
            $openAi = $toBrl($openAiUsd, 'USD');
            $costs = $stripeFees + $openAi + $fixedTotal;

            return [
                'month' => $month,
                'revenue' => round($revenue, 2),
                'refunds' => round($refunds, 2),
                'net_revenue' => round($revenue - $refunds, 2),
                'stripe_fees' => round($stripeFees, 2),
                'openai' => round($openAi, 2),
                'openai_usd' => round($openAiUsd, 2),
                'fixed_costs' => round($fixedTotal, 2),
                'costs' => round($costs, 2),
                'profit' => round($revenue - $refunds - $costs, 2),
            ];
        })->values()->all();
    }

    private function storeMonth(string $provider, string $month, float $amount, string $currency): void
    {
        DB::table('platform_cost_months')->updateOrInsert(['provider' => $provider, 'month' => $month], ['amount' => $amount, 'currency' => $currency, 'fetched_at' => now()]);
    }

    /** @return string[] meses YYYY-MM do mês de $from até o atual */
    private function months(Carbon $from): array
    {
        $months = [];
        for ($m = $from->copy()->startOfMonth(); $m->lte(now()); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function monthRange(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "{$month}-01 00:00:00");

        return [$start, $start->copy()->endOfMonth()];
    }
}
