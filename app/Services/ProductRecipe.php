<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\Printer;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Quote;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Ficha técnica do produto: o custo de uma unidade (insumos + tempo de impressão, com o
 * mesmo cálculo do orçamento) e a baixa dos insumos quando a unidade é produzida.
 */
class ProductRecipe
{
    /** Etapas em que a peça sob encomenda já está sendo (ou foi) produzida. */
    public const CONSUMING_STAGES = [
        Quote::PRODUCTION_IN_PRODUCTION,
        Quote::PRODUCTION_FINISHED,
        Quote::PRODUCTION_DELIVERED,
    ];

    public function __construct(private QuoteCalculatorService $calculator) {}

    /**
     * Insumos de uma unidade: as linhas comuns mais as da variação, somando o mesmo insumo
     * quando ele aparece nas duas.
     *
     * @return Collection<int, array{material: Material, quantity: float}>
     */
    public function linesFor(Product $product, ?ProductVariation $variation = null): Collection
    {
        $product->loadMissing('recipeLines.material.category');

        return $product->recipeLines
            ->filter(fn ($line) => $line->material
                && ($line->product_variation_id === null || ($variation && $line->product_variation_id === $variation->id)))
            ->groupBy('material_id')
            ->map(fn ($group) => [
                'material' => $group->first()->material,
                'quantity' => (float) $group->sum('quantity'),
            ])
            ->values();
    }

    /** Tem algo na ficha (insumo ou tempo de impressão) pra calcular custo? */
    public function hasRecipe(Product $product): bool
    {
        $product->loadMissing('recipeLines');

        return $product->recipeLines->isNotEmpty()
            || (int) $product->print_time_minutes > 0
            || (int) $product->postprocess_minutes > 0
            || $product->variations->contains(fn ($v) => (int) $v->print_time_minutes > 0);
    }

    /** Custo de uma unidade do produto (ou da variação) pela ficha. */
    public function unitCost(Product $product, ?ProductVariation $variation = null): array
    {
        $product->loadMissing('company.setting', 'printer');

        return $this->breakdown(
            $this->linesFor($product, $variation),
            (int) ($variation?->print_time_minutes ?? $product->print_time_minutes),
            (int) $product->postprocess_minutes,
            $product->printer,
            $product->company?->setting,
        );
    }

    /**
     * @param  Collection<int, array{material: Material, quantity: float}>  $lines
     */
    public function breakdown(Collection $lines, int $printTimeMinutes, int $postprocessMinutes, ?Printer $printer, ?Setting $setting): array
    {
        $calc = $this->calculator->calculate([
            'quantity' => 1,
            'print_time_minutes' => $printTimeMinutes,
            'setup_minutes' => 0,
            'postprocess_minutes' => $postprocessMinutes,
            'extra_costs' => 0,
            'markup_percent' => 0,
        ], $lines->map(fn ($l) => ['material' => $l['material'], 'weight' => $l['quantity']])->all(), $printer, $setting);

        return [
            'material_cost' => $calc['material_cost'],
            'materials' => $calc['materials'],
            'energy_cost' => $calc['energy_cost'],
            'depreciation_cost' => $calc['depreciation_cost'],
            'labor_cost' => $calc['labor_cost'],
            'failure_rate_percent' => $calc['failure_rate_percent'],
            'failure_cost' => $calc['failure_cost'],
            'total' => $calc['print_total_cost'],
        ];
    }

    /**
     * Recalcula o custo gravado no produto e nas variações, quando o custo é pela ficha.
     * Variação sem insumo próprio nem tempo próprio fica sem custo (herda o do produto).
     */
    public function refreshCost(Product $product): void
    {
        if (! $product->auto_cost) {
            return;
        }

        $product->unsetRelation('recipeLines');
        $product->load('variations');

        $product->forceFill(['cost' => $this->unitCost($product)['total']])->saveQuietly();

        $variationIdsWithLines = $product->recipeLines->whereNotNull('product_variation_id')->pluck('product_variation_id')->unique();

        foreach ($product->variations as $variation) {
            $own = $variationIdsWithLines->contains($variation->id) || $variation->print_time_minutes !== null;
            $variation->forceFill(['cost' => $own ? $this->unitCost($product, $variation)['total'] : null])->saveQuietly();
        }
    }

    /** Preço do insumo mudou: atualiza o custo dos produtos que usam ele. */
    public function refreshForMaterial(Material $material): void
    {
        Product::where('company_id', $material->company_id)
            ->where('auto_cost', true)
            ->whereHas('recipeLines', fn ($q) => $q->where('material_id', $material->id))
            ->get()
            ->each(fn (Product $product) => $this->refreshCost($product));
    }

    /** Energia, mão de obra, taxa de falha ou uma impressora mudou: atualiza todos os produtos com custo pela ficha. */
    public function refreshCompany(int $companyId): void
    {
        Product::where('company_id', $companyId)
            ->where('auto_cost', true)
            ->get()
            ->each(fn (Product $product) => $this->refreshCost($product));
    }

    /**
     * Baixa os insumos de $units unidades. Deixa o saldo ficar negativo e devolve os insumos
     * que ficaram faltando, pra quem chamou avisar. Deve rodar dentro de uma transação.
     *
     * @return array<int, array{material_id: int, name: string, unit: string, needed: float, balance: float}>
     */
    public function consume(Product $product, ?ProductVariation $variation, int $units, string $type, array $context = []): array
    {
        $lines = $this->linesFor($product, $variation);
        if ($lines->isEmpty() || $units <= 0) {
            return [];
        }

        $materials = Material::with('category')
            ->whereIn('id', $lines->pluck('material.id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $context += ['product_id' => $product->id, 'product_variation_id' => $variation?->id];
        $shortages = [];

        foreach ($lines as $line) {
            $material = $materials->get($line['material']->id);
            $needed = round($line['quantity'] * $units, 2);

            if (! MaterialStock::move($material, -$needed, $type, $context)) {
                continue; // estoque do insumo não é controlado
            }

            if ((float) $material->stock_quantity < 0) {
                $shortages[] = [
                    'material_id' => $material->id,
                    'name' => $material->name,
                    'unit' => $material->unitLabel(),
                    'needed' => $needed,
                    'balance' => (float) $material->stock_quantity,
                ];
            }
        }

        return $shortages;
    }

    /**
     * Pedido entrou em produção: baixa os insumos das unidades que não saíram do estoque pronto
     * (sob encomenda). Cada item baixa uma vez só, mesmo que o pedido volte e avance de novo.
     */
    public function consumeForQuote(Quote $quote): void
    {
        $items = $quote->items()
            ->where('type', 'product')
            ->whereNotNull('product_id')
            ->where('stock_deducted', false)
            ->whereNull('materials_consumed_at')
            ->get();

        foreach ($items as $item) {
            $product = Product::find($item->product_id);
            if (! $product) {
                continue;
            }

            $variation = $item->product_variation_id ? $product->variations->firstWhere('id', $item->product_variation_id) : null;

            $this->consume($product, $variation, (int) $item->quantity, MaterialMovement::TYPE_ORDER_PRODUCTION, [
                'quote_id' => $quote->id,
                'note' => "Pedido #{$quote->id}",
            ]);

            $item->forceFill(['materials_consumed_at' => now()])->save();
        }
    }

    /**
     * Venda cancelada: devolve o que foi baixado quando o pedido entrou em produção. Usa o
     * histórico (e não a ficha atual), pra devolver exatamente o que saiu mesmo que a ficha
     * tenha mudado depois.
     */
    public function restoreForQuote(Quote $quote): void
    {
        $net = MaterialMovement::where('quote_id', $quote->id)
            ->whereIn('type', [MaterialMovement::TYPE_ORDER_PRODUCTION, MaterialMovement::TYPE_ORDER_RETURN])
            ->selectRaw('material_id, SUM(quantity) as total')
            ->groupBy('material_id')
            ->pluck('total', 'material_id');

        $toReturn = $net->filter(fn ($total) => (float) $total < 0);

        if ($toReturn->isNotEmpty()) {
            $materials = Material::whereIn('id', $toReturn->keys())->lockForUpdate()->get()->keyBy('id');

            foreach ($toReturn as $materialId => $total) {
                $material = $materials->get($materialId);
                if ($material) {
                    MaterialStock::move($material, abs((float) $total), MaterialMovement::TYPE_ORDER_RETURN, [
                        'quote_id' => $quote->id,
                        'note' => "Pedido #{$quote->id} cancelado",
                    ]);
                }
            }
        }

        $quote->items()->whereNotNull('materials_consumed_at')->update(['materials_consumed_at' => null]);
    }
}
