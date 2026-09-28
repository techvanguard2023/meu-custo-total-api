<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Gates dos planos pagos. Toda verificação de plano acontece aqui, no
 * backend — o frontend apenas espelha com telas de upsell. Três níveis:
 * Gratuito (limitado), Essencial (uso ilimitado + catálogo/PDF/Caixa/avaliações)
 * e Pro (tudo do Essencial + automação/operação: bot de WhatsApp, Linha de
 * Produção, relatórios, expositores, coleções e métricas completas).
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

    /** Essencial ou Pro. */
    private function isEssential(Request $request): bool
    {
        return $request->user()->company->hasEssential();
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

    /** Bloqueia recursos dos planos pagos (Essencial ou Pro). */
    private function requireEssential(Request $request, string $feature): void
    {
        abort_unless(
            $this->isEssential($request),
            403,
            "\"{$feature}\" é um recurso dos planos Essencial e Pro. Assine para desbloquear."
        );
    }

    /** Bloqueia criação além do limite do plano gratuito — Essencial e Pro são ilimitados. */
    private function enforceFreeLimit(Request $request, string $resource, int $currentCount, string $label): void
    {
        if ($this->isEssential($request)) {
            return;
        }

        $limit = self::FREE_LIMITS[$resource];

        abort_unless(
            $currentCount < $limit,
            403,
            "Limite do plano gratuito atingido ({$limit} {$label}). Assine o Essencial ou o Pro para cadastrar sem limites."
        );
    }
}
