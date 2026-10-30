<?php

namespace App\Services;

use App\Models\CashbackEntry;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cashback retroativo pros clientes que já compravam antes do programa: as compras dos últimos N meses
 * que teriam gerado cashback (entregues, pagas, sem marketplace/expositor/cortesia) ganham o crédito
 * agora, com validade contando de hoje. Cada crédito fica ligado ao pedido (tipo "purchase"), então a
 * mesma compra nunca credita duas vezes e o cancelamento estorna como qualquer outra.
 *
 * O aviso aos clientes vai pra uma fila (cashback_notifications) que o agendador esvazia aos poucos.
 */
class CashbackRetroactive
{
    public function __construct(private WhatsAppMessenger $messenger) {}

    /** Pedidos que ainda não geraram cashback e entram no retroativo. */
    private function eligibleQuotes(Company $company, int $months): Collection
    {
        return Quote::where('company_id', $company->id)
            ->where('status', Quote::STATUS_APPROVED)
            ->where('production_status', Quote::PRODUCTION_DELIVERED)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->subMonths($months))
            ->whereNotNull('customer_id')
            ->whereNull('sales_channel_id')
            ->whereNull('display_id')
            ->where('is_courtesy', false)
            ->whereNotExists(fn ($q) => $q->selectRaw(1)
                ->from('cashback_entries')
                ->whereColumn('cashback_entries.quote_id', 'quotes.id')
                ->where('cashback_entries.type', CashbackEntry::TYPE_PURCHASE))
            ->get(['id', 'customer_id', 'final_price', 'paid_at']);
    }

    /** Prévia: quem recebe, quanto, e o total — nada é gravado. */
    public function preview(Company $company, int $months): array
    {
        $percent = (float) $company->setting->cashback_purchase_percent;
        $quotes = $this->eligibleQuotes($company, $months);
        $customers = Customer::whereIn('id', $quotes->pluck('customer_id')->unique())->get(['id', 'name', 'phone'])->keyBy('id');

        $rows = $quotes->groupBy('customer_id')
            ->map(function (Collection $group, $customerId) use ($customers, $percent) {
                $customer = $customers->get($customerId);

                return [
                    'customer_id' => (int) $customerId,
                    'name' => $customer?->name ?? 'Cliente removido',
                    'has_phone' => (bool) $customer?->phone,
                    'orders' => $group->count(),
                    'paid' => round((float) $group->sum('final_price'), 2),
                    'credit' => round($group->sum(fn ($q) => round((float) $q->final_price * $percent / 100, 2)), 2),
                ];
            })
            ->filter(fn ($row) => $row['credit'] > 0)
            ->sortByDesc('credit')
            ->values();

        return [
            'months' => $months,
            'percent' => $percent,
            'customers_count' => $rows->count(),
            'orders_count' => $rows->sum('orders'),
            'total' => round($rows->sum('credit'), 2),
            'with_phone' => $rows->where('has_phone', true)->count(),
            'customers' => $rows->all(),
        ];
    }

    /** Credita e, se pedido, enfileira os avisos (um por cliente, espaçados de 1 em 1 minuto). */
    public function apply(Company $company, int $months, bool $notify): array
    {
        $setting = $company->setting;
        $percent = (float) $setting->cashback_purchase_percent;
        $expires = now()->addMonths(max(1, (int) $setting->cashback_validity_months));

        $credited = DB::transaction(function () use ($company, $months, $percent, $expires) {
            $byCustomer = [];

            foreach ($this->eligibleQuotes($company, $months) as $quote) {
                $amount = round((float) $quote->final_price * $percent / 100, 2);
                if ($amount <= 0) {
                    continue;
                }

                CashbackEntry::create([
                    'company_id' => $company->id,
                    'customer_id' => $quote->customer_id,
                    'type' => CashbackEntry::TYPE_PURCHASE,
                    'amount' => $amount,
                    'remaining' => $amount,
                    'expires_at' => $expires,
                    'quote_id' => $quote->id,
                    'user_id' => auth()->id(),
                    'note' => "Cashback retroativo — compra #{$quote->id} de ".$quote->paid_at->format('d/m/Y'),
                ]);

                $byCustomer[$quote->customer_id] = round(($byCustomer[$quote->customer_id] ?? 0) + $amount, 2);
            }

            return $byCustomer;
        });

        $queued = 0;
        if ($notify && $credited) {
            $queued = $this->queueNotifications($company, $credited, $months, $expires);
        }

        return [
            'customers_count' => count($credited),
            'total' => round(array_sum($credited), 2),
            'notifications_queued' => $queued,
        ];
    }

    /** @param array<int, float> $credited cliente => valor creditado */
    private function queueNotifications(Company $company, array $credited, int $months, $expires): int
    {
        $customers = Customer::whereIn('id', array_keys($credited))->whereNotNull('phone')->orderBy('name')->get();
        $money = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $setting = $company->setting;
        $termsUrl = rtrim($company->catalogUrl(), '/').'/regras-cashback';

        // Depois do último aviso já na fila (de um lote anterior), pra nunca encavalar
        $start = DB::table('cashback_notifications')
            ->where('company_id', $company->id)
            ->whereNull('sent_at')
            ->whereNull('failed_at')
            ->max('send_after');
        $at = $start ? \Illuminate\Support\Carbon::parse($start)->addMinute() : now();

        foreach ($customers as $customer) {
            $first = explode(' ', trim($customer->name))[0];
            $message = implode("\n", [
                'Oi'.($first !== '' ? ", {$first}" : '')."! Aqui é da {$company->name} 😊",
                '',
                'Agora temos *cashback*: '.(float) $setting->cashback_purchase_percent.'% de cada compra volta como crédito para usar na próxima.',
                "E como você já é nosso cliente, creditamos *{$money($credited[$customer->id])}* referente às suas compras dos últimos {$months} meses 🎉",
                '',
                'O crédito vale até '.$expires->copy()->timezone($company->timezone ?: 'America/Sao_Paulo')->format('d/m/Y').' e cobre até '.(float) $setting->cashback_max_redeem_percent.'% de um pedido. É só pedir para usar quando for comprar.',
                "Regras: {$termsUrl}",
            ]);

            DB::table('cashback_notifications')->insert([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'message' => $message,
                'amount' => $credited[$customer->id],
                'valid_until' => $expires,
                'send_after' => $at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $at = $at->copy()->addMinute();
        }

        return $customers->count();
    }

    /**
     * Envia, por loja, o aviso mais antigo que já está na hora — no máximo um por loja a cada execução
     * (o agendador roda a cada minuto). WhatsApp desconectado: tenta de novo depois, até 3 vezes.
     */
    public function sendDue(): int
    {
        $sent = 0;

        $companyIds = DB::table('cashback_notifications')
            ->whereNull('sent_at')
            ->whereNull('failed_at')
            ->where('send_after', '<=', now())
            ->distinct()
            ->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $row = DB::table('cashback_notifications')
                ->where('company_id', $companyId)
                ->whereNull('sent_at')
                ->whereNull('failed_at')
                ->where('send_after', '<=', now())
                ->orderBy('send_after')
                ->orderBy('id')
                ->first();

            $company = Company::find($companyId);
            $customer = Customer::find($row?->customer_id);
            // Conta com exclusão agendada: a fila espera (se desfizer, os avisos saem; senão, somem com a conta)
            if (! $row || ! $company || ! $customer || $company->isPendingDeletion()) {
                continue;
            }

            // Com valor: vai o cartão "Você ganhou R$ X de cashback" com o texto de legenda
            $image = $row->amount !== null
                ? app(CashbackImage::class)->render($company, $customer->name, (float) $row->amount, \Illuminate\Support\Carbon::parse($row->valid_until ?? now()->addMonths(6))->timezone($company->timezone ?: 'America/Sao_Paulo'))
                : null;
            $result = $this->messenger->deliverImage($company, $customer->phone, $image, $row->message);

            if ($result['sent']) {
                DB::table('cashback_notifications')->where('id', $row->id)->update(['sent_at' => now(), 'updated_at' => now()]);
                $sent++;

                continue;
            }

            // WhatsApp desconectado: a fila só espera (tenta de novo no próximo minuto, sem gastar tentativa)
            if ($result['reason'] === 'not_connected') {
                DB::table('cashback_notifications')->where('id', $row->id)->update(['error' => 'not_connected', 'updated_at' => now()]);

                continue;
            }

            // Sem telefone não tem como mandar; falha de envio tenta de novo em 10 min, até 3 vezes
            $attempts = $row->attempts + 1;
            $giveUp = $result['reason'] === 'no_phone' || $attempts >= 3;
            DB::table('cashback_notifications')->where('id', $row->id)->update([
                'attempts' => $attempts,
                'error' => $result['reason'],
                'send_after' => now()->addMinutes(10),
                'failed_at' => $giveUp ? now() : null,
                'updated_at' => now(),
            ]);
            Log::warning("Aviso de cashback não enviado (empresa {$companyId}, cliente {$customer->id}): {$result['reason']}");
        }

        return $sent;
    }

    /** Situação da fila de avisos da loja (a tela mostra o andamento). */
    public function queueStatus(Company $company): array
    {
        $base = DB::table('cashback_notifications')->where('company_id', $company->id);

        return [
            'pending' => (clone $base)->whereNull('sent_at')->whereNull('failed_at')->count(),
            'sent' => (clone $base)->whereNotNull('sent_at')->count(),
            'failed' => (clone $base)->whereNotNull('failed_at')->count(),
        ];
    }
}
