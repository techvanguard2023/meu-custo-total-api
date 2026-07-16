<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Gates do plano gratuito. Toda verificação de plano acontece aqui,
 * no backend — o frontend apenas espelha com telas de upsell.
 */
trait EnforcesPlanLimits
{
    /** Limites do plano gratuito. */
    public const FREE_LIMITS = [
        'printers' => 1,
        'products' => 5,
        'customers' => 10,
        'quotes_per_month' => 10,
    ];

    private function isPro(Request $request): bool
    {
        return $request->user()->company->isPro();
    }

    /** Bloqueia recursos exclusivos do plano Pro. */
    private function requirePro(Request $request, string $feature): void
    {
        abort_unless(
            $this->isPro($request),
            403,
            "\"{$feature}\" é um recurso exclusivo do plano Pro. Assine para desbloquear."
        );
    }

    /** Bloqueia criação além do limite do plano gratuito. */
    private function enforceFreeLimit(Request $request, string $resource, int $currentCount, string $label): void
    {
        if ($this->isPro($request)) {
            return;
        }

        $limit = self::FREE_LIMITS[$resource];

        abort_unless(
            $currentCount < $limit,
            403,
            "Limite do plano gratuito atingido ({$limit} {$label}). Assine o Pro para cadastrar sem limites."
        );
    }
}
