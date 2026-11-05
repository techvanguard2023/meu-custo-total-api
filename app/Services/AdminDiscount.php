<?php

namespace App\Services;

use App\Models\Company;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * Desconto na assinatura dado pelo admin a uma loja: cria um cupom na Stripe só pra ela. Se a loja já assina, o
 * cupom entra na assinatura agora (vale a partir da próxima fatura); se não assina, fica guardado e o checkout
 * usa quando ela assinar (PlanController). Um desconto por vez: dar outro troca o anterior.
 */
class AdminDiscount
{
    public const DURATIONS = ['once', 'repeating', 'forever'];

    /**
     * @param array{type: string, value: float|int, duration: string, months?: int|null, note?: string|null} $data
     * $expiresAt: até quando dá pra usar (só pra loja que ainda não assina — ex: oferta do fim do teste grátis).
     */
    public function give(Company $company, array $data, ?\Carbon\CarbonInterface $expiresAt = null, string $source = 'admin'): array
    {
        $stripe = Cashier::stripe();
        $percent = $data['type'] === 'percent';
        $currency = config('cashier.currency') ?: 'brl';

        $coupon = $stripe->coupons->create(array_filter([
            'name' => mb_substr('Desconto '.$company->name, 0, 40),
            'percent_off' => $percent ? (float) $data['value'] : null,
            'amount_off' => $percent ? null : (int) round($data['value'] * 100),
            'currency' => $percent ? null : $currency,
            'duration' => $data['duration'],
            'duration_in_months' => $data['duration'] === 'repeating' ? (int) $data['months'] : null,
            'redeem_by' => $expiresAt?->getTimestamp(),
            'metadata' => ['company_id' => (string) $company->id, 'source' => $source],
        ], fn ($v) => $v !== null));

        $previous = $company->admin_discount;
        $subscription = $company->subscribed('default') ? $company->subscription('default') : null;
        if ($subscription) {
            $subscription->applyCoupon($coupon->id);
        }

        $company->forceFill(['admin_discount' => [
            'coupon_id' => $coupon->id,
            'type' => $data['type'],
            'value' => (float) $data['value'],
            'duration' => $data['duration'],
            'months' => $data['duration'] === 'repeating' ? (int) $data['months'] : null,
            'note' => $data['note'] ?? null,
            'status' => $subscription ? 'applied' : 'pending',
            'created_at' => now()->toIso8601String(),
            'applied_at' => $subscription ? now()->toIso8601String() : null,
            // Pendente com prazo: some do checkout e da tela de Planos depois disso
            'expires_at' => $subscription ? null : $expiresAt?->toIso8601String(),
            'source' => $source,
        ]])->save();

        // O cupom anterior sai da Stripe (desconto que já estava numa assinatura foi trocado pelo novo acima)
        if ($previous['coupon_id'] ?? null) {
            $this->deleteCoupon($previous['coupon_id']);
        }

        return $company->admin_discount;
    }

    /** Desconto pendente (a loja ainda não assinou) que passou do prazo. */
    public static function expired(?array $discount): bool
    {
        return ($discount['status'] ?? null) === 'pending'
            && ! empty($discount['expires_at'])
            && \Illuminate\Support\Carbon::parse($discount['expires_at'])->isPast();
    }

    /** Tira o desconto: da assinatura (se estiver nela) e da Stripe. */
    public function remove(Company $company): void
    {
        $current = $company->admin_discount;
        if (! $current) {
            return;
        }

        $subscription = $company->subscription('default');
        if ($subscription && $subscription->valid()) {
            $discount = $subscription->discount();
            if ($discount && $discount->coupon()->asStripeCoupon()->id === $current['coupon_id']) {
                Cashier::stripe()->subscriptions->deleteDiscount($subscription->stripe_id);
            }
        }

        $this->deleteCoupon($current['coupon_id']);
        $company->forceFill(['admin_discount' => null])->save();
    }

    /**
     * O que a ficha da loja mostra. Pendente que já virou assinatura (a loja assinou com o cupom) passa a
     * "aplicado"; com a assinatura, conta também até quando vale segundo a Stripe.
     */
    public function present(Company $company): ?array
    {
        $current = $company->admin_discount;
        if (! $current) {
            return null;
        }

        $subscription = $company->subscribed('default') ? $company->subscription('default') : null;
        if ($subscription && $current['status'] === 'pending') {
            $current['status'] = 'applied';
            $current['applied_at'] = $subscription->created_at?->toIso8601String();
            $company->forceFill(['admin_discount' => $current])->save();
        }

        $current['label'] = $this->label($current);
        $current['ends_at'] = null;
        $current['active_on_subscription'] = null;
        if ($subscription) {
            try {
                $discount = $subscription->discount();
                $current['active_on_subscription'] = $discount && $discount->coupon()->asStripeCoupon()->id === $current['coupon_id'];
                $current['ends_at'] = $discount?->end()?->toIso8601String();
            } catch (Throwable) {
                // Stripe sem resposta: a ficha mostra o que está guardado
            }
        }

        return $current;
    }

    public function label(array $d): string
    {
        $value = $d['type'] === 'percent'
            ? rtrim(rtrim(number_format($d['value'], 2, ',', ''), '0'), ',').'%'
            : 'R$ '.number_format($d['value'], 2, ',', '.');

        return $value.' de desconto '.match ($d['duration']) {
            // Pra quem ainda não assina, a "próxima fatura" é a primeira
            'once' => ($d['status'] ?? null) === 'pending' ? 'no 1º mês' : 'na próxima fatura',
            'repeating' => "por {$d['months']} ".($d['months'] === 1 ? 'mês' : 'meses'),
            default => 'em todas as faturas',
        };
    }

    private function deleteCoupon(string $couponId): void
    {
        try {
            Cashier::stripe()->coupons->delete($couponId);
        } catch (Throwable) {
            // já apagado na Stripe: nada a fazer
        }
    }
}
