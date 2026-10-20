<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialMovement;

/**
 * Toda mudança no saldo de um insumo passa por aqui, pra ficar registrada no histórico.
 * Insumo sem controle de estoque (stock_quantity nulo) fica de fora — não há saldo pra mexer.
 */
class MaterialStock
{
    /**
     * Soma (ou subtrai, com $delta negativo) do saldo e registra a movimentação. O saldo pode
     * ficar negativo: às vezes há um carretel em uso que ainda não foi lançado no sistema.
     * Quem chama deve ter travado o insumo (lockForUpdate) dentro de uma transação.
     *
     * @param  array{product_id?: int|null, product_variation_id?: int|null, quote_id?: int|null, user_id?: int|null, note?: string|null}  $context
     */
    public static function move(Material $material, float $delta, string $type, array $context = []): ?MaterialMovement
    {
        $delta = round($delta, 2);

        if ($material->stock_quantity === null || $delta == 0) {
            return null;
        }

        $material->increment('stock_quantity', $delta);

        return self::record($material, $delta, $type, $context);
    }

    /** Registra uma mudança de saldo que já foi gravada no insumo (ex: edição do cadastro). */
    public static function record(Material $material, float $delta, string $type, array $context = []): MaterialMovement
    {
        return MaterialMovement::create([
            'company_id' => $material->company_id,
            'material_id' => $material->id,
            'type' => $type,
            'quantity' => round($delta, 2),
            'balance_after' => $material->stock_quantity,
            'product_id' => $context['product_id'] ?? null,
            'product_variation_id' => $context['product_variation_id'] ?? null,
            'quote_id' => $context['quote_id'] ?? null,
            'user_id' => $context['user_id'] ?? auth()->id(),
            'note' => isset($context['note']) ? mb_substr($context['note'], 0, 255) : null,
        ]);
    }
}
