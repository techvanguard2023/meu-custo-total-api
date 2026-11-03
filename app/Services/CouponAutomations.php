<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\CouponAutomation;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Cupom automático de reativação (Pro): cliente que já comprou e sumiu há X dias ganha um cupom pessoal
 * (só ele usa, uma vez) e recebe pelo WhatsApp da loja. Os cupons são gerados uma vez por dia (com limite
 * diário) e enviados aos poucos — um por loja por minuto, das 9h às 20h no horário da loja.
 */
class CouponAutomations
{
    public const SEND_FROM_HOUR = 9;
    public const SEND_UNTIL_HOUR = 20;

    /** Sem conseguir enviar em 2 dias (WhatsApp desconectado), o cupom é descartado e o cliente volta pra fila. */
    private const SEND_WINDOW_DAYS = 2;

    /** Letras e números sem ambiguidade (sem 0/O, 1/I/L) pro código pessoal. */
    private const CODE_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private WhatsAppMessenger $messenger) {}

    public function settings(Company $company, string $type = CouponAutomation::TYPE_REACTIVATION): CouponAutomation
    {
        return CouponAutomation::firstOrNew(['company_id' => $company->id, 'type' => $type], CouponAutomation::DEFAULTS);
    }

    /**
     * Quem sumiu: já fez compra direta (sem marketplace nem expositor), não tem pedido nem orçamento nos
     * últimos X dias e ainda não ganhou cupom de reativação depois do último pedido. Cadastros duplicados do
     * mesmo telefone contam juntos (o pedido recente em qualquer um deles vale); quem pediu pra não receber
     * em qualquer cadastro fica de fora. Os que sumiram há menos tempo vêm primeiro (voltam mais fácil).
     *
     * @return Collection<int, array{customer: Customer, last_order_at: Carbon}>
     */
    public function reactivationCandidates(Company $company, CouponAutomation $automation): Collection
    {
        $customers = Customer::where('company_id', $company->id)->whereNotNull('phone')->get(['id', 'company_id', 'name', 'phone', 'promo_opt_out']);
        if ($customers->isEmpty()) {
            return collect();
        }

        $activity = Quote::where('company_id', $company->id)
            ->whereNotNull('customer_id')
            ->whereNotIn('status', [Quote::STATUS_REJECTED, Quote::STATUS_CANCELLED])
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(created_at) as last_at')
            ->selectRaw('SUM(CASE WHEN status = ? AND sales_channel_id IS NULL AND display_id IS NULL THEN 1 ELSE 0 END) as purchases', [Quote::STATUS_APPROVED])
            ->get()->keyBy('customer_id');

        $lastCoupon = Coupon::where('company_id', $company->id)->where('automation', CouponAutomation::TYPE_REACTIVATION)
            ->groupBy('customer_id')->selectRaw('customer_id, MAX(created_at) as last_at')
            ->pluck('last_at', 'customer_id');

        $cutoff = now()->subDays(max(1, $automation->inactive_days));

        return $customers
            ->groupBy(fn (Customer $c) => Customer::phoneKey($c->phone) ?? 'id'.$c->id)
            ->map(function (Collection $group) use ($activity, $lastCoupon) {
                $withActivity = $group->filter(fn ($c) => isset($activity[$c->id]))->sortByDesc(fn ($c) => $activity[$c->id]->last_at);
                $lastOrder = $withActivity->isNotEmpty() ? Carbon::parse($activity[$withActivity->first()->id]->last_at) : null;
                $couponAt = $group->map(fn ($c) => $lastCoupon[$c->id] ?? null)->filter()->max();

                return [
                    'customer' => $withActivity->first() ?? $group->first(),
                    'last_order_at' => $lastOrder,
                    'purchases' => $group->sum(fn ($c) => (int) ($activity[$c->id]->purchases ?? 0)),
                    'opt_out' => $group->contains(fn ($c) => $c->promo_opt_out),
                    'coupon_at' => $couponAt ? Carbon::parse($couponAt) : null,
                ];
            })
            ->filter(fn ($r) => $r['purchases'] > 0
                && ! $r['opt_out']
                && $r['last_order_at']?->lt($cutoff)
                && (! $r['coupon_at'] || $r['coupon_at']->lt($r['last_order_at'])))
            ->sortByDesc(fn ($r) => $r['last_order_at']->timestamp)
            ->map(fn ($r) => ['customer' => $r['customer'], 'last_order_at' => $r['last_order_at']])
            ->values();
    }

    /** Gera os cupons do dia (respeitando o limite diário). Devolve quantos foram criados. */
    public function generate(Company $company): int
    {
        $automation = $this->settings($company);
        if (! $automation->exists || ! $automation->enabled || ! $company->isPro() || $company->isPendingDeletion()) {
            return 0;
        }

        $tz = $company->timezone ?: 'America/Sao_Paulo';
        $today = now($tz);
        $createdToday = Coupon::where('company_id', $company->id)->where('automation', CouponAutomation::TYPE_REACTIVATION)
            ->where('created_at', '>=', $today->copy()->startOfDay()->utc())->count();
        $room = max(0, $automation->daily_limit - $createdToday);
        if ($room === 0) {
            return 0;
        }

        $created = 0;
        foreach ($this->reactivationCandidates($company, $automation)->take($room) as $row) {
            $customer = $row['customer'];
            Coupon::create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'automation' => CouponAutomation::TYPE_REACTIVATION,
                'code' => $this->personalCode($company, $customer),
                'description' => "Reativação — {$customer->name}",
                'type' => $automation->discount_type,
                'value' => $automation->value,
                'min_order' => $automation->min_order,
                'ends_on' => $today->copy()->addDays(max(1, $automation->validity_days))->toDateString(),
                'usage_limit' => 1,
                'per_customer_limit' => 1,
                'allow_promotions' => $automation->allow_promotions,
                'active' => true,
            ]);
            $created++;
        }

        return $created;
    }

    /** Envia o próximo cupom pendente de cada loja (dentro do horário). Devolve quantos saíram. */
    public function sendDue(): int
    {
        $sent = 0;
        $companyIds = Coupon::whereNotNull('automation')->whereNull('notified_at')->where('active', true)
            ->where(fn ($q) => $q->whereNull('notify_error')->orWhereIn('notify_error', ['not_connected', 'failed']))
            ->distinct()->pluck('company_id');

        foreach (Company::active()->whereIn('id', $companyIds)->get() as $company) {
            $hour = now($company->timezone ?: 'America/Sao_Paulo')->hour;
            if ($hour < self::SEND_FROM_HOUR || $hour >= self::SEND_UNTIL_HOUR) {
                continue;
            }

            $coupon = Coupon::where('company_id', $company->id)->whereNotNull('automation')->whereNull('notified_at')->where('active', true)
                ->where(fn ($q) => $q->whereNull('notify_error')->orWhereIn('notify_error', ['not_connected', 'failed']))
                ->with('customer')->orderBy('id')->first();
            if (! $coupon) {
                continue;
            }

            // Não saiu a tempo: descarta (nunca foi visto) e o cliente pode ganhar outro num próximo dia
            if ($coupon->created_at->lt(now()->subDays(self::SEND_WINDOW_DAYS)) || ! $coupon->customer) {
                $coupon->delete();

                continue;
            }

            $result = $this->messenger->deliver($company, $coupon->customer->phone, $this->message($company, $coupon));
            if ($result['sent']) {
                $coupon->update(['notified_at' => now(), 'notify_error' => null]);
                $sent++;

                continue;
            }

            // Sem telefone válido / sem WhatsApp: desliga o cupom (fica registrado e não tenta de novo até outra compra)
            $final = in_array($result['reason'], ['no_phone', 'not_on_whatsapp'], true);
            $coupon->update(['notify_error' => $result['reason'], 'active' => ! $final]);
        }

        return $sent;
    }

    public function message(Company $company, Coupon $coupon): string
    {
        $first = Str::of($coupon->customer?->name ?? '')->trim()->explode(' ')->first() ?: 'tudo bem';
        $money = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $until = $coupon->ends_on?->format('d/m');

        $lines = [
            "Oi, {$first}! Aqui é da {$company->name}. Faz um tempinho que você não aparece e sentimos sua falta 💛",
            '',
            "Separamos um cupom só seu: *{$coupon->code}* — {$coupon->label()} de desconto"
                .((float) $coupon->min_order > 0 ? ' em pedidos a partir de '.$money((float) $coupon->min_order) : '')
                .($until ? ", válido até {$until}." : '.'),
            '',
            $company->hasCatalogActive()
                ? 'É só abrir o catálogo que o cupom já entra no carrinho: '.$company->catalogUrl().'?cupom='.$coupon->code
                : 'É só responder esta mensagem com o seu pedido e informar o cupom.',
        ];

        return implode("\n", $lines);
    }

    /** Números dos últimos 90 dias + os cupons mais recentes, pra tela. */
    public function report(Company $company): array
    {
        $since = now()->subDays(90);
        $coupons = Coupon::where('company_id', $company->id)->where('automation', CouponAutomation::TYPE_REACTIVATION)
            ->where('created_at', '>=', $since)
            ->withCount(['usedQuotes as uses_count'])->withSum(['usedQuotes as revenue_total'], 'final_price')
            ->with('customer:id,name')->latest('id')->get();

        return [
            'sent' => $coupons->whereNotNull('notified_at')->count(),
            'used' => $coupons->where('uses_count', '>', 0)->count(),
            'revenue' => round((float) $coupons->sum('revenue_total'), 2),
            'recent' => $coupons->take(30)->map(fn (Coupon $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'customer' => $c->customer?->name,
                'created_at' => $c->created_at?->toIso8601String(),
                'notified_at' => $c->notified_at?->toIso8601String(),
                'notify_error' => $c->notify_error,
                'ends_on' => $c->ends_on?->toDateString(),
                'used' => $c->uses_count > 0,
                'revenue' => round((float) $c->revenue_total, 2),
            ])->values(),
        ];
    }

    private function personalCode(Company $company, Customer $customer): string
    {
        $first = Str::upper(Str::ascii(explode(' ', trim($customer->name))[0] ?? ''));
        $prefix = substr(preg_replace('/[^A-Z]/', '', $first), 0, 8) ?: 'VOLTA';

        do {
            $suffix = '';
            for ($i = 0; $i < 4; $i++) {
                $suffix .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
            }
            $code = "{$prefix}-{$suffix}";
        } while (Coupon::where('company_id', $company->id)->where('code', $code)->exists());

        return $code;
    }
}
