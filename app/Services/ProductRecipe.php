<?php

namespace App\Services;

use App\Models\Company;
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
        // Preço sugerido = custo + margem padrão, igual à calculadora rápida e ao orçamento. O pedido
        // mínimo fica de fora: ele vale pro pedido inteiro, não pra cada unidade do produto.
        $unitSetting = $setting?->replicate()->forceFill(['minimum_order_price' => 0]);

        $calc = $this->calculator->calculate([
            'quantity' => 1,
            'print_time_minutes' => $printTimeMinutes,
            'setup_minutes' => 0,
            'postprocess_minutes' => $postprocessMinutes,
            'extra_costs' => 0,
        ], $lines->map(fn ($l) => ['material' => $l['material'], 'weight' => $l['quantity']])->all(), $printer, $unitSetting);

        return [
            'material_cost' => $calc['material_cost'],
            'materials' => $calc['materials'],
            'energy_cost' => $calc['energy_cost'],
            'depreciation_cost' => $calc['depreciation_cost'],
            'labor_cost' => $calc['labor_cost'],
            'failure_rate_percent' => $calc['failure_rate_percent'],
            'failure_cost' => $calc['failure_cost'],
            'total' => $calc['print_total_cost'],
            'markup_percent' => $calc['markup_percent'],
            'suggested_price' => $calc['final_price'],
            'suggested_profit' => $calc['profit_amount'],
        ];
    }

    /**
     * Recalcula o custo gravado no produto e nas variações, quando o custo é pela ficha — e o
     * preço de venda também, quando ele segue o preço sugerido (custo + margem).
     * Variação sem insumo próprio nem tempo próprio fica sem custo e sem preço (herda os do produto).
     * `$fresh` false: quem chama já carregou ficha, variações, impressora e empresa (recálculo em lote).
     */
    public function refreshCost(Product $product, bool $fresh = true): void
    {
        if (! $product->auto_cost) {
            return;
        }

        if ($fresh) {
            $product->unsetRelation('recipeLines');
            $product->load('variations');
        }

        $base = $this->unitCost($product);
        $product->forceFill(array_filter([
            'cost' => $base['total'],
            'sale_price' => $product->auto_price ? $base['suggested_price'] : null,
        ], fn ($value) => $value !== null))->saveQuietly();

        $variationIdsWithLines = $product->recipeLines->whereNotNull('product_variation_id')->pluck('product_variation_id')->unique();

        foreach ($product->variations as $variation) {
            $hasOwnRecipe = $variationIdsWithLines->contains($variation->id) || $variation->print_time_minutes !== null;
            $own = $hasOwnRecipe ? $this->unitCost($product, $variation) : null;
            $variation->forceFill(array_merge(
                ['cost' => $own['total'] ?? null],
                $product->auto_price ? ['sale_price' => $own['suggested_price'] ?? null] : [],
            ))->saveQuietly();
        }

        // Gravou sem eventos (pra não recalcular em cascata): o preço no catálogo pode ter mudado
        CatalogCache::forget($product->company_id);
    }

    /** Preço do insumo mudou: atualiza o custo dos produtos que usam ele. */
    public function refreshForMaterial(Material $material): void
    {
        $this->refreshMany(
            Product::where('company_id', $material->company_id)
                ->where('auto_cost', true)
                ->whereHas('recipeLines', fn ($q) => $q->where('material_id', $material->id)),
            $material->company_id,
        );
    }

    /** Energia, mão de obra, taxa de falha ou uma impressora mudou: atualiza todos os produtos com custo pela ficha. */
    public function refreshCompany(int $companyId): void
    {
        $this->refreshMany(Product::where('company_id', $companyId)->where('auto_cost', true), $companyId);
    }

    /**
     * Recálculo em lote: ficha, insumos, variações e impressora de todos os produtos vêm numa leva só, e a
     * empresa (com as configurações) é a mesma pra todos — antes, cada produto buscava tudo de novo.
     */
    private function refreshMany($query, int $companyId): void
    {
        $company = Company::with('setting')->find($companyId);

        $query->with(['recipeLines.material.category', 'variations', 'printer'])->get()
            ->each(function (Product $product) use ($company) {
                $product->setRelation('company', $company);
                $this->refreshCost($product, fresh: false);
            });
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
        // Venda de expositor sai de peças já prontas que estão lá — nunca baixa insumo
        if ($quote->display_id) {
            return;
        }

        $items = $quote->items()
            ->where('type', 'product')
            ->whereNotNull('product_id')
            ->where('stock_deducted', false)
            ->whereNull('materials_consumed_at')
            ->get();
        if ($items->isEmpty()) {
            return;
        }

        // Tudo numa leva só: produtos com ficha e variações, e os insumos travados uma vez
        $products = Product::with(['recipeLines.material.category', 'variations'])
            ->whereIn('id', $items->pluck('product_id')->unique())->get()->keyBy('id');

        $needs = []; // [item, linhas da ficha]
        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            if ($product) {
                $variation = $item->product_variation_id ? $product->variations->firstWhere('id', $item->product_variation_id) : null;
                $needs[] = [$item, $product, $variation, $this->linesFor($product, $variation)];
            }
        }

        $materials = Material::whereIn('id', collect($needs)->flatMap(fn ($n) => $n[3]->pluck('material.id'))->unique())
            ->lockForUpdate()->get()->keyBy('id');

        // Saldo de cada insumo andando item a item (pro histórico ter o saldo depois de cada baixa); no fim, uma
        // atualização por insumo e todas as movimentações numa gravação só — mesmo resultado do MaterialStock::move
        $now = now();
        $balances = $materials->map(fn (Material $m) => $m->stock_quantity === null ? null : (float) $m->stock_quantity);
        $movements = [];
        foreach ($needs as [$item, $product, $variation, $lines]) {
            foreach ($lines as $line) {
                $id = $line['material']->id;
                $delta = round(-$line['quantity'] * (int) $item->quantity, 2);
                if (! isset($balances[$id]) || $delta == 0) {
                    continue; // estoque do insumo não é controlado
                }
                $balances[$id] = round($balances[$id] + $delta, 2);
                $movements[] = [
                    'company_id' => $materials[$id]->company_id,
                    'material_id' => $id,
                    'type' => MaterialMovement::TYPE_ORDER_PRODUCTION,
                    'quantity' => $delta,
                    'balance_after' => $balances[$id],
                    'product_id' => $product->id,
                    'product_variation_id' => $variation?->id,
                    'quote_id' => $quote->id,
                    'user_id' => auth()->id(),
                    'note' => "Pedido #{$quote->id}",
                    'created_at' => $now,
                ];
            }
        }

        foreach (collect($movements)->groupBy('material_id') as $materialId => $rows) {
            Material::whereKey($materialId)->increment('stock_quantity', round($rows->sum('quantity'), 2));
        }
        if ($movements) {
            MaterialMovement::insert($movements);
        }

        $quote->items()->whereIn('id', collect($needs)->map(fn ($n) => $n[0]->id))->update(['materials_consumed_at' => $now]);
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
