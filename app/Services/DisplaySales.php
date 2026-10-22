<?php

namespace App\Services;

use App\Models\Display;
use App\Models\DisplayStockLine;
use App\Models\DisplayStockMovement;
use App\Models\Quote;
use Illuminate\Support\Collection;

/**
 * Vendas de um expositor em consignação. Saem de duas formas: apuradas na conferência (o lojista
 * conta o que sobrou — dinheiro recebido na hora) ou lançadas pelo parceiro na área dele (o dinheiro
 * ainda está no caixa do parceiro: fica "a receber" até o repasse).
 */
class DisplaySales
{
    public const SOURCE_RECONCILIATION = 'reconciliation';
    public const SOURCE_PARTNER = 'partner';

    public function __construct(private QuoteCalculatorService $calculator) {}

    /**
     * Cria a venda (já aprovada e entregue) com os itens e a comissão do parceiro. Não mexe no
     * estoque do expositor — quem chama já debitou. Deve rodar dentro de uma transação.
     *
     * @param  array<int, array{product: \App\Models\Product, variation: \App\Models\ProductVariation|null, quantity: int}>  $productLines
     */
    public function createSale(Display $display, array $productLines, string $source): Quote
    {
        $company = $display->company;
        $breakdown = $this->calculator->calculate(['quantity' => 1], [], null, $company->setting, $productLines, null, $display);
        $paid = $source === self::SOURCE_RECONCILIATION;

        $quote = $company->quotes()->create([
            'name' => $source === self::SOURCE_PARTNER ? "Venda lançada pelo parceiro — {$display->name}" : "Conferência — {$display->name}",
            'quantity' => 1,
            'print_time_minutes' => 0,
            'material_weight_g' => 0,
            'extra_costs' => 0,
            'discount_amount' => 0,
            'material_cost' => 0,
            'energy_cost' => 0,
            'depreciation_cost' => 0,
            'labor_cost' => 0,
            'failure_rate_percent' => 0,
            'failure_cost' => 0,
            'subtotal_cost' => 0,
            'markup_percent' => $breakdown['markup_percent'],
            'final_price' => $breakdown['final_price'],
            'unit_price' => $breakdown['unit_price'],
            'profit_amount' => $breakdown['profit_amount'],
            'display_id' => $display->id,
            'display_commission_amount' => $breakdown['display_commission_amount'],
            'display_source' => $source,
            'status' => Quote::STATUS_APPROVED,
            'production_status' => Quote::PRODUCTION_DELIVERED,
            'production_order' => (int) Quote::where('company_id', $company->id)
                ->where('status', Quote::STATUS_APPROVED)
                ->where('production_status', Quote::PRODUCTION_DELIVERED)
                ->max('production_order') + 1,
            'approved_at' => now(),
            'is_courtesy' => false,
            'amount_paid' => $paid ? $breakdown['final_price'] : 0,
            'paid_at' => $paid ? now() : null,
        ]);

        foreach ($breakdown['products'] as $line) {
            $quote->items()->create([
                'product_id' => $line['product_id'],
                'product_variation_id' => $line['product_variation_id'] ?? null,
                'description' => $line['name'],
                'type' => 'product',
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'unit_cost' => $line['unit_cost'],
                'amount' => $line['line_total'],
                // Saiu do expositor, não do estoque principal: cancelar não devolve pra lá
                'stock_deducted' => false,
            ]);
        }

        return $quote;
    }

    /**
     * Venda do expositor cancelada (pelo lojista ou desfeita pelo parceiro): as unidades voltam
     * pro expositor e saem do extrato. Deve rodar dentro de uma transação.
     */
    public function returnToDisplay(Quote $quote): void
    {
        $movements = DisplayStockMovement::where('quote_id', $quote->id)
            ->where('type', DisplayStockMovement::TYPE_SALE)
            ->get();

        foreach ($movements as $movement) {
            $line = DisplayStockLine::where('display_id', $movement->display_id)
                ->where('product_id', $movement->product_id)
                ->where('product_variation_id', $movement->product_variation_id)
                ->lockForUpdate()
                ->first();

            $line?->increment('quantity_current', $movement->quantity);
            $movement->delete();
        }
    }

    /** Vendas lançadas pelo parceiro que ainda não foram repassadas. */
    public function unsettled(Display $display): Collection
    {
        return $display->quotes()
            ->where('display_source', self::SOURCE_PARTNER)
            ->where('status', Quote::STATUS_APPROVED)
            ->whereNull('paid_at')
            ->get();
    }

    /** Quanto o parceiro tem a repassar: o que vendeu menos a comissão dele. */
    public static function amountToSettle(Collection $quotes): float
    {
        return round($quotes->sum(fn (Quote $q) => (float) $q->final_price - (float) $q->display_commission_amount), 2);
    }
}
