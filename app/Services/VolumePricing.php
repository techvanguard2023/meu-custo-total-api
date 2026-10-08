<?php

namespace App\Services;

/**
 * Regras de preço por volume (recurso Pro):
 *  - faixas por produto: "a partir de N un, cada uma sai por R$ X" (ou X% de desconto);
 *  - faixas por valor do pedido, gerais da loja: "a partir de R$ X, Y% de desconto".
 * Funções puras — o cálculo do pedido (QuoteCalculatorService) e o catálogo público usam as mesmas.
 */
class VolumePricing
{
    /**
     * Faixa do produto que vale para essa quantidade: a de maior quantidade mínima já atingida.
     *
     * @param  array<int, array{min_quantity: int, type: string, value: float|int|string}>|null  $tiers
     * @return array{min_quantity: int, type: string, value: float|int|string}|null
     */
    public static function productTier(?array $tiers, int $quantity): ?array
    {
        return collect($tiers ?? [])
            ->filter(fn ($t) => $quantity >= (int) $t['min_quantity'])
            ->sortByDesc(fn ($t) => (int) $t['min_quantity'])
            ->first();
    }

    /** Preço unitário da faixa: valor fixo, ou o preço de tabela menos X%. */
    public static function tierUnitPrice(array $tier, float $basePrice): float
    {
        return $tier['type'] === 'percent'
            ? round($basePrice * (1 - (float) $tier['value'] / 100), 2)
            : round((float) $tier['value'], 2);
    }

    /**
     * Faixa de desconto do pedido que vale para esse subtotal.
     *
     * @param  array<int, array{min_total: float|int|string, percent: float|int|string}>|null  $tiers
     * @return array{min_total: float|int|string, percent: float|int|string}|null
     */
    public static function orderTier(?array $tiers, float $subtotal): ?array
    {
        return collect($tiers ?? [])
            ->filter(fn ($t) => $subtotal >= (float) $t['min_total'])
            ->sortByDesc(fn ($t) => (float) $t['min_total'])
            ->first();
    }
}
