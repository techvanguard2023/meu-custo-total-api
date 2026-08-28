<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Display;
use App\Models\DisplayStockLine;
use App\Models\DisplayStockMovement;
use App\Models\DisplayVisit;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\QuoteCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Expositor: estoque de produtos em consignação numa loja parceira. O
 * lojista repõe produtos (saem do estoque principal) e, de tempos em
 * tempos, confere quanto sobrou — a diferença vira venda automaticamente,
 * já com a comissão do parceiro deduzida do lucro. Recurso Pro.
 */
class DisplayController extends Controller
{
    use EnforcesPlanLimits;

    public function __construct(private QuoteCalculatorService $calculator) {}

    public function index(Request $request)
    {
        $this->requirePro($request, 'Expositores');

        return $request->user()->company->displays()
            ->withCount(['stockLines as products_count' => fn ($q) => $q->where('quantity_current', '>', 0)])
            ->withSum('quotes', 'final_price')
            ->withSum('quotes', 'profit_amount')
            ->latest()
            ->get();
    }

    public function show(Request $request, Display $display)
    {
        $this->requirePro($request, 'Expositores');
        $this->authorizeCompany($request, $display);

        $display->load([
            'stockLines' => fn ($q) => $q->where('quantity_current', '>', 0)->with(['product', 'variation']),
            'quotes' => fn ($q) => $q->latest()->with('items.product'),
            'visits' => fn ($q) => $q->latest()->with(['quote.items', 'movements.product', 'movements.variation']),
        ]);

        return response()->json([
            ...$display->toArray(),
            ...$this->salesBreakdown($display),
        ]);
    }

    public function store(Request $request)
    {
        $this->requirePro($request, 'Expositores');

        $data = $this->validated($request);
        // Marca o início do teste de 30 dias a partir de hoje, a não ser que
        // o lojista informe outra data (ex: registrando um expositor que já
        // estava lá antes de começar a usar o sistema).
        $data['started_at'] ??= now()->toDateString();

        $display = $request->user()->company->displays()->create($data);

        return response()->json($display, 201);
    }

    public function update(Request $request, Display $display)
    {
        $this->requirePro($request, 'Expositores');
        $this->authorizeCompany($request, $display);

        $data = $this->validated($request);
        // Status "ended" só sai pelo endpoint de fechar (devolve o estoque) —
        // atualizar aqui não pode deixar o expositor "encerrado" com estoque parado.
        $data['status'] = $request->input('status') === Display::STATUS_ENDED
            ? $display->status
            : ($request->input('status') ?? $display->status);

        $display->update($data);

        return response()->json($display->fresh());
    }

    /** Bloqueado se ainda houver estoque físico registrado — encerre o expositor primeiro. */
    public function destroy(Request $request, Display $display)
    {
        $this->requirePro($request, 'Expositores');
        $this->authorizeCompany($request, $display);

        $hasStock = $display->stockLines()->where('quantity_current', '>', 0)->exists();
        abort_if($hasStock, 422, 'Este expositor ainda tem produtos registrados. Encerre-o (devolvendo o estoque) antes de excluir.');

        $display->delete();

        return response()->json(null, 204);
    }

    /**
     * Reposição: leva produtos pro expositor. Desconta do estoque principal
     * (mesmo estoque usado no Catálogo, Caixa e Orçamentos) e soma no
     * estoque do expositor.
     */
    public function restock(Request $request, Display $display)
    {
        $this->requirePro($request, 'Expositores');
        $this->authorizeCompany($request, $display);
        abort_if($display->status === Display::STATUS_ENDED, 422, 'Este expositor está encerrado.');

        $this->normalizeLinesInput($request);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_variation_id' => ['sometimes', 'nullable', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], $this->photoMessages());

        $resolved = $this->resolveLines($request, $data['lines']);
        $photoPath = $this->storePhoto($request);

        DB::transaction(function () use ($display, $resolved, $photoPath) {
            $visit = DisplayVisit::create([
                'display_id' => $display->id,
                'type' => DisplayVisit::TYPE_RESTOCK,
                'photo_path' => $photoPath,
            ]);

            foreach ($resolved as $line) {
                $target = $line['variation']
                    ? ProductVariation::lockForUpdate()->find($line['variation']->id)
                    : Product::lockForUpdate()->find($line['product']->id);

                abort_unless(
                    $target->stock_quantity >= $line['quantity'],
                    422,
                    "Estoque insuficiente de \"{$line['label']}\": disponível {$target->stock_quantity}, pedido {$line['quantity']}."
                );

                $target->decrement('stock_quantity', $line['quantity']);

                $stockLine = DisplayStockLine::firstOrCreate(
                    [
                        'display_id' => $display->id,
                        'product_id' => $line['product']->id,
                        'product_variation_id' => $line['variation']?->id,
                    ],
                    ['quantity_current' => 0]
                );
                $stockLine->increment('quantity_current', $line['quantity']);

                DisplayStockMovement::create([
                    'display_id' => $display->id,
                    'display_visit_id' => $visit->id,
                    'product_id' => $line['product']->id,
                    'product_variation_id' => $line['variation']?->id,
                    'type' => DisplayStockMovement::TYPE_RESTOCK,
                    'quantity' => $line['quantity'],
                ]);
            }
        });

        return response()->json($display->fresh()->load('stockLines.product', 'stockLines.variation'));
    }

    /**
     * Retirada: tira produto do expositor sem virar venda nem perda — volta
     * pro estoque principal. É o que permite trocar um item que não está
     * vendendo por outro (retira um, repõe outro pelo endpoint de reposição).
     */
    public function retrieve(Request $request, Display $display)
    {
        $this->requirePro($request, 'Expositores');
        $this->authorizeCompany($request, $display);
        abort_if($display->status === Display::STATUS_ENDED, 422, 'Este expositor está encerrado.');

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_variation_id' => ['sometimes', 'nullable', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $resolved = $this->resolveLines($request, $data['lines']);

        DB::transaction(function () use ($display, $resolved) {
            foreach ($resolved as $line) {
                $stockLine = DisplayStockLine::where('display_id', $display->id)
                    ->where('product_id', $line['product']->id)
                    ->where('product_variation_id', $line['variation']?->id)
                    ->lockForUpdate()
                    ->first();

                abort_unless($stockLine, 404, "\"{$line['label']}\" não está registrado neste expositor.");
                abort_unless(
                    $stockLine->quantity_current >= $line['quantity'],
                    422,
                    "\"{$line['label']}\": só tem {$stockLine->quantity_current} no expositor, pedido {$line['quantity']}."
                );

                $stockLine->decrement('quantity_current', $line['quantity']);

                $target = $line['variation']
                    ? ProductVariation::lockForUpdate()->find($line['variation']->id)
                    : Product::lockForUpdate()->find($line['product']->id);
                $target?->increment('stock_quantity', $line['quantity']);

                DisplayStockMovement::create([
                    'display_id' => $display->id,
                    'product_id' => $line['product']->id,
                    'product_variation_id' => $line['variation']?->id,
                    'type' => DisplayStockMovement::TYPE_RETURN,
                    'quantity' => $line['quantity'],
                ]);
            }
        });

        return response()->json($display->fresh()->load('stockLines.product', 'stockLines.variation'));
    }

    /**
     * Conferência: quanto sobrou (e quanto se perdeu) de cada produto. O que
     * não está mais lá nem foi contado como perda virou venda — gera a venda
     * automaticamente, com a comissão do parceiro já deduzida do lucro.
     */
    public function reconcile(Request $request, Display $display)
    {
        $this->requirePro($request, 'Expositores');
        $this->authorizeCompany($request, $display);
        abort_if($display->status === Display::STATUS_ENDED, 422, 'Este expositor está encerrado.');

        $this->normalizeLinesInput($request);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_variation_id' => ['sometimes', 'nullable', 'integer'],
            'lines.*.remaining' => ['required', 'integer', 'min:0'],
            'lines.*.lost' => ['sometimes', 'integer', 'min:0'],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], $this->photoMessages());

        $resolved = $this->resolveLines($request, $data['lines']);
        $setting = $request->user()->company->setting;
        $photoPath = $this->storePhoto($request);

        $quote = DB::transaction(function () use ($display, $resolved, $setting, $request, $photoPath) {
            $productLines = [];
            $soldByLine = [];

            foreach ($resolved as $key => $line) {
                $stockLine = DisplayStockLine::where('display_id', $display->id)
                    ->where('product_id', $line['product']->id)
                    ->where('product_variation_id', $line['variation']?->id)
                    ->lockForUpdate()
                    ->first();

                abort_unless($stockLine, 404, "\"{$line['label']}\" não está registrado neste expositor.");

                $remaining = (int) $line['remaining'];
                $lost = (int) ($line['lost'] ?? 0);
                $expected = $stockLine->quantity_current;
                $sold = $expected - $remaining - $lost;

                abort_unless(
                    $remaining >= 0 && $lost >= 0 && $sold >= 0,
                    422,
                    "\"{$line['label']}\": restam + perdidos não pode passar do que estava lá ({$expected})."
                );

                if ($sold > 0) {
                    $productLines[] = ['product' => $line['product'], 'variation' => $line['variation'], 'quantity' => $sold];
                }

                $soldByLine[$key] = ['sold' => $sold, 'lost' => $lost, 'remaining' => $remaining, 'stockLine' => $stockLine];
            }

            abort_if(count($productLines) === 0, 422, 'Nenhum produto vendido nesta conferência — nada a registrar.');

            $breakdown = $this->calculator->calculate(
                ['quantity' => 1],
                [],
                null,
                $setting,
                $productLines,
                null,
                $display
            );

            $quote = $request->user()->company->quotes()->create([
                'name' => "Conferência — {$display->name}",
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
                'status' => Quote::STATUS_APPROVED,
                'production_status' => Quote::PRODUCTION_DELIVERED,
                'production_order' => (int) Quote::where('company_id', $request->user()->company_id)
                    ->where('status', Quote::STATUS_APPROVED)
                    ->where('production_status', Quote::PRODUCTION_DELIVERED)
                    ->max('production_order') + 1,
                'approved_at' => now(),
                'is_courtesy' => false,
                'amount_paid' => $breakdown['final_price'],
                'paid_at' => now(),
            ]);

            $visit = DisplayVisit::create([
                'display_id' => $display->id,
                'type' => DisplayVisit::TYPE_RECONCILIATION,
                'photo_path' => $photoPath,
                'quote_id' => $quote->id,
            ]);

            foreach ($breakdown['products'] as $productLine) {
                $quote->items()->create([
                    'product_id' => $productLine['product_id'],
                    'product_variation_id' => $productLine['product_variation_id'] ?? null,
                    'description' => $productLine['name'],
                    'type' => 'product',
                    'quantity' => $productLine['quantity'],
                    'unit_price' => $productLine['unit_price'],
                    'unit_cost' => $productLine['unit_cost'],
                    'amount' => $productLine['line_total'],
                ]);
            }

            foreach ($soldByLine as $key => $line) {
                $resolvedLine = $resolved[$key];
                /** @var DisplayStockLine $stockLine */
                $stockLine = $line['stockLine'];

                $stockLine->update(['quantity_current' => $line['remaining']]);

                if ($line['sold'] > 0) {
                    DisplayStockMovement::create([
                        'display_id' => $display->id,
                        'display_visit_id' => $visit->id,
                        'product_id' => $resolvedLine['product']->id,
                        'product_variation_id' => $resolvedLine['variation']?->id,
                        'type' => DisplayStockMovement::TYPE_SALE,
                        'quantity' => $line['sold'],
                        'quote_id' => $quote->id,
                    ]);
                }

                if ($line['lost'] > 0) {
                    DisplayStockMovement::create([
                        'display_id' => $display->id,
                        'display_visit_id' => $visit->id,
                        'product_id' => $resolvedLine['product']->id,
                        'product_variation_id' => $resolvedLine['variation']?->id,
                        'type' => DisplayStockMovement::TYPE_LOSS,
                        'quantity' => $line['lost'],
                    ]);
                }
            }

            return $quote;
        });

        return response()->json($quote->load('items.product'), 201);
    }

    /** Encerra o expositor e devolve tudo que sobrou de estoque pro estoque principal. */
    public function close(Request $request, Display $display)
    {
        $this->requirePro($request, 'Expositores');
        $this->authorizeCompany($request, $display);
        abort_if($display->status === Display::STATUS_ENDED, 422, 'Este expositor já está encerrado.');

        DB::transaction(function () use ($display) {
            $stockLines = $display->stockLines()->where('quantity_current', '>', 0)->lockForUpdate()->get();

            foreach ($stockLines as $stockLine) {
                $target = $stockLine->product_variation_id
                    ? ProductVariation::lockForUpdate()->find($stockLine->product_variation_id)
                    : Product::lockForUpdate()->find($stockLine->product_id);

                $target?->increment('stock_quantity', $stockLine->quantity_current);

                DisplayStockMovement::create([
                    'display_id' => $display->id,
                    'product_id' => $stockLine->product_id,
                    'product_variation_id' => $stockLine->product_variation_id,
                    'type' => DisplayStockMovement::TYPE_RETURN,
                    'quantity' => $stockLine->quantity_current,
                ]);

                $stockLine->update(['quantity_current' => 0]);
            }

            $display->update(['status' => Display::STATUS_ENDED, 'ended_at' => now()]);
        });

        return response()->json($display->fresh()->load('stockLines'));
    }

    /**
     * Ranking de produtos e categorias vendidos neste expositor — soma de todas as
     * conferências já feitas. É o que mostra "esse ponto vende muito cachorro,
     * mas quase não vende frase", sem precisar somar nada na mão.
     */
    private function salesBreakdown(Display $display): array
    {
        $products = QuoteItem::query()
            ->whereHas('quote', fn ($q) => $q->where('display_id', $display->id)->where('status', Quote::STATUS_APPROVED))
            ->selectRaw('product_id, SUM(quantity) as quantity_sold, SUM(amount) as revenue')
            ->groupBy('product_id')
            ->with('product')
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id,
                'name' => $row->product?->name ?? 'Produto removido',
                'category_name' => $row->product?->category?->name,
                'quantity_sold' => (int) $row->quantity_sold,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->sortByDesc('quantity_sold')
            ->values();

        $categories = $products
            ->groupBy(fn ($p) => $p['category_name'] ?? 'Sem categoria')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'quantity_sold' => $group->sum('quantity_sold'),
                'revenue' => round($group->sum('revenue'), 2),
            ])
            ->sortByDesc('quantity_sold')
            ->values();

        return [
            'top_products' => $products->all(),
            'top_categories' => $categories->all(),
        ];
    }

    /**
     * "lines" chega como array normal numa chamada JSON, mas como texto JSON
     * quando vem junto de uma foto (multipart/form-data não sabe carregar
     * array aninhado) — decodifica antes da validação, se for o caso.
     */
    private function normalizeLinesInput(Request $request): void
    {
        $lines = $request->input('lines');
        if (is_string($lines)) {
            $decoded = json_decode($lines, true);
            abort_unless(is_array($decoded), 422, 'Formato de "lines" inválido.');
            $request->merge(['lines' => $decoded]);
        }
    }

    /** Foto de evidência da visita — uma por reposição/conferência, opcional. */
    private function storePhoto(Request $request): ?string
    {
        return $request->hasFile('photo') ? $request->file('photo')->store('display-visits', 'public') : null;
    }

    private function photoMessages(): array
    {
        return [
            'photo.max' => 'A foto deve ter no máximo 4MB.',
            'photo.image' => 'Envie um arquivo de imagem válido (JPG, PNG ou WebP).',
            'photo.mimes' => 'Formato não suportado — envie JPG, PNG ou WebP.',
        ];
    }

    /**
     * @param  array<int, array{product_id: int, product_variation_id?: int|null, quantity?: int, remaining?: int, lost?: int}>  $lines
     * @return array<int, array{product: Product, variation: ?ProductVariation, label: string, quantity?: int, remaining?: int, lost?: int}>
     */
    private function resolveLines(Request $request, array $lines): array
    {
        $companyId = $request->user()->company_id;

        $products = Product::where('company_id', $companyId)
            ->whereIn('id', collect($lines)->pluck('product_id'))
            ->with('variations')
            ->get()
            ->keyBy('id');

        return collect($lines)->map(function (array $line) use ($products) {
            $product = $products->get($line['product_id']);
            abort_unless($product, 404, 'Produto não encontrado.');

            $variation = null;
            $variationId = $line['product_variation_id'] ?? null;

            if ($variationId) {
                $variation = $product->variations->firstWhere('id', (int) $variationId);
                abort_unless($variation, 422, "Variação inválida para o produto \"{$product->name}\".");
            } elseif ($product->has_variations) {
                abort(422, "Escolha uma variação para o produto \"{$product->name}\".");
            }

            return [
                ...$line,
                'product' => $product,
                'variation' => $variation,
                'label' => $variation ? "{$product->name} ({$variation->display_name})" : $product->name,
            ];
        })->all();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // O código que o lojista já usa pra identificar o móvel fisicamente
            // (etiqueta no expositor) — livre, sem formato imposto.
            'code' => ['nullable', 'string', 'max:60'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'status' => ['sometimes', Rule::in([Display::STATUS_TESTING, Display::STATUS_ACTIVE, Display::STATUS_PAUSED])],
            'started_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function authorizeCompany(Request $request, Display $display): void
    {
        abort_unless($display->company_id === $request->user()->company_id, 403);
    }
}
