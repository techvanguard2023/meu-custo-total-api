<?php

namespace App\Services;

use App\Models\Display;
use App\Models\Material;
use App\Models\Printer;
use App\Models\SalesChannel;
use App\Models\Setting;

class QuoteCalculatorService
{
    /**
     * Calcula o breakdown completo de custo e preço de um orçamento.
     *
     * @param  array{
     *     quantity: int,
     *     print_time_minutes?: int|null,
     *     setup_minutes?: int,
     *     postprocess_minutes?: int,
     *     extra_costs?: float,
     *     failure_rate_percent?: float|null,
     *     markup_percent?: float|null,
     *     discount_amount?: float,
     * }  $data
     * @param  array<int, array{material: Material, weight: float}>  $materialLines
     * @param  array<int, array{product: \App\Models\Product, quantity: int}>  $productLines
     */
    public function calculate(array $data, array $materialLines, ?Printer $printer, ?Setting $setting, array $productLines = [], ?SalesChannel $channel = null, ?Display $display = null): array
    {
        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $printTimeHours = ((int) ($data['print_time_minutes'] ?? 0)) / 60;
        $setupMinutes = (int) ($data['setup_minutes'] ?? 0);
        $postprocessMinutes = (int) ($data['postprocess_minutes'] ?? 0);
        $extraCosts = (float) ($data['extra_costs'] ?? 0);
        $discount = (float) ($data['discount_amount'] ?? 0);

        $failureRate = $data['failure_rate_percent'] ?? $setting?->default_failure_rate ?? 0;
        $markup = $data['markup_percent'] ?? $setting?->default_markup ?? 0;
        $electricityRate = $setting?->electricity_rate_kwh ?? 0;
        $laborRate = $setting?->labor_hour_rate ?? 0;

        // Cada material consumido (principal + insumos extras) soma ao custo,
        // multiplicado pela quantidade do pedido (mesma fórmula para todos).
        $materialLinesOut = [];
        $materialCost = 0.0;
        foreach ($materialLines as $line) {
            $material = $line['material'];
            $weight = (float) $line['weight'];
            $lineCost = $weight * (float) $material->cost_per_g * $quantity;
            $materialCost += $lineCost;
            $materialLinesOut[] = [
                'material_id' => $material->id,
                'name' => $material->name,
                'weight' => $weight,
                'unit_cost' => (float) $material->cost_per_g,
                'line_total' => round($lineCost, 2),
            ];
        }

        $energyCost = $printer
            ? ((float) $printer->power_watts / 1000) * $printTimeHours * (float) $electricityRate * $quantity
            : 0;

        $depreciationCost = 0;
        if ($printer && $printer->lifespan_hours > 0) {
            $depreciationCost = ((float) $printer->purchase_price / $printer->lifespan_hours) * $printTimeHours * $quantity;
            $depreciationCost += $depreciationCost * ((float) $printer->maintenance_percent / 100);
        }

        // setup é feito uma vez por pedido; postprocess é por peça
        $setupCost = ($setupMinutes / 60) * (float) $laborRate;
        $postprocessCost = ($postprocessMinutes / 60) * (float) $laborRate * $quantity;
        $laborCost = $setupCost + $postprocessCost;

        $subtotalCost = $materialCost + $energyCost + $depreciationCost + $laborCost + $extraCosts;

        $failureCost = $subtotalCost * ((float) $failureRate / 100);

        $totalCost = $subtotalCost + $failureCost;

        // Produtos prontos: sem taxa de falha (já foram produzidos).
        // Preço unitário = sale_price quando definido; senão custo + markup do orçamento.
        $productLinesOut = [];
        $productsCost = 0.0;
        $productsTotal = 0.0;
        foreach ($productLines as $line) {
            $product = $line['product'];
            $variation = $line['variation'] ?? null;
            $lineQty = max(1, (int) $line['quantity']);

            // Preço e custo saem da variação quando houver; em branco lá, herdam do produto.
            $unitCost = $variation ? $variation->effectiveCost($product) : (float) $product->cost;
            $definedPrice = $variation ? $variation->effectivePrice($product) : ($product->sale_price !== null ? (float) $product->sale_price : null);
            $unitPrice = $definedPrice ?? round($unitCost * (1 + ((float) $markup / 100)), 2);

            $lineCost = $unitCost * $lineQty;
            $lineTotal = $unitPrice * $lineQty;
            $productsCost += $lineCost;
            $productsTotal += $lineTotal;
            $productLinesOut[] = [
                'product_id' => $product->id,
                'product_variation_id' => $variation?->id,
                'name' => $variation ? "{$product->name} ({$variation->display_name})" : $product->name,
                'quantity' => $lineQty,
                'unit_cost' => round($unitCost, 2),
                'unit_price' => round($unitPrice, 2),
                'line_cost' => round($lineCost, 2),
                'line_total' => round($lineTotal, 2),
                'stock_quantity' => (int) ($variation ? $variation->stock_quantity : $product->stock_quantity),
            ];
        }

        // Desconto aplicado após o markup, sobre o total combinado.
        $finalPrice = max(0, ($totalCost * (1 + ((float) $markup / 100))) + $productsTotal - $discount);

        if ($setting && $finalPrice < (float) $setting->minimum_order_price) {
            $finalPrice = (float) $setting->minimum_order_price;
        }

        // Vendendo por um canal (Mercado Livre, Shopee...) que desconta comissão +
        // taxa fixa do valor recebido: o preço de tabela sobe o suficiente pra sobrar,
        // líquido, o mesmo que uma venda direta — a margem não muda, só o preço exibido.
        $channelFeeAmount = 0.0;
        if ($channel) {
            $commissionPercent = min(99.99, max(0, (float) $channel->commission_percent));
            $fixedFee = max(0, (float) $channel->fixed_fee);
            $priceWithChannel = ($finalPrice + $fixedFee) / (1 - $commissionPercent / 100);
            $channelFeeAmount = round($priceWithChannel - $finalPrice, 2);
            $finalPrice = round($priceWithChannel, 2);
        }

        // Venda apurada num expositor em consignação: o preço ao consumidor é fixo
        // (não sobe pra compensar, diferente do canal de marketplace) — a comissão do
        // parceiro só sai do seu lucro.
        $displayCommissionAmount = 0.0;
        if ($display) {
            $commissionPercent = min(99.99, max(0, (float) $display->commission_percent));
            $displayCommissionAmount = round($finalPrice * $commissionPercent / 100, 2);
        }

        $grandTotalCost = $totalCost + $productsCost;

        $unitPrice = $finalPrice / $quantity;
        // Lucro é sobre o que fica depois da taxa do canal e da comissão do expositor.
        $profitAmount = ($finalPrice - $channelFeeAmount) - $grandTotalCost - $displayCommissionAmount;

        return [
            'material_cost' => round($materialCost, 2),
            'materials' => $materialLinesOut,
            'energy_cost' => round($energyCost, 2),
            'depreciation_cost' => round($depreciationCost, 2),
            'labor_cost' => round($laborCost, 2),
            'extra_costs' => round($extraCosts, 2),
            'subtotal_cost' => round($subtotalCost, 2),
            'failure_rate_percent' => round((float) $failureRate, 2),
            'failure_cost' => round($failureCost, 2),
            'print_total_cost' => round($totalCost, 2),
            'products' => $productLinesOut,
            'products_cost' => round($productsCost, 2),
            'products_total' => round($productsTotal, 2),
            'total_cost' => round($grandTotalCost, 2),
            'discount_amount' => round($discount, 2),
            'markup_percent' => round((float) $markup, 2),
            'final_price' => round($finalPrice, 2),
            'quantity' => $quantity,
            'unit_price' => round($unitPrice, 2),
            'profit_amount' => round($profitAmount, 2),
            'sales_channel_id' => $channel?->id,
            'sales_channel_name' => $channel?->name,
            'channel_fee_amount' => round($channelFeeAmount, 2),
            'display_id' => $display?->id,
            'display_commission_amount' => round($displayCommissionAmount, 2),
        ];
    }
}
