<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Printer;
use App\Models\Product;
use App\Models\Quote;
use App\Services\QuoteCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteController extends Controller
{
    public function __construct(private QuoteCalculatorService $calculator) {}

    public function index(Request $request)
    {
        return $request->user()->company->quotes()
            ->with(['customer', 'printer', 'material', 'items.product'])
            ->latest()
            ->get();
    }

    public function show(Request $request, Quote $quote)
    {
        $this->authorizeCompany($request, $quote);

        return $quote->load(['customer', 'printer', 'material', 'items.product']);
    }

    public function preview(Request $request)
    {
        $data = $this->validated($request);
        [$material, $printer] = $this->resolveEntities($request, $data);
        $productLines = $this->resolveProducts($request, $data);
        $setting = $request->user()->company->setting;

        return response()->json(
            $this->calculator->calculate($data, $material, $printer, $setting, $productLines)
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        [$material, $printer] = $this->resolveEntities($request, $data);
        $productLines = $this->resolveProducts($request, $data);
        $setting = $request->user()->company->setting;

        $breakdown = $this->calculator->calculate($data, $material, $printer, $setting, $productLines);

        $quote = DB::transaction(function () use ($request, $data, $breakdown) {
            $quote = $request->user()->company->quotes()->create(
                $this->quoteAttributes($data, $breakdown, Quote::STATUS_SENT)
            );
            $this->syncProductItems($quote, $breakdown);

            return $quote;
        });

        return response()->json($quote->load(['customer', 'printer', 'material', 'items.product']), 201);
    }

    public function update(Request $request, Quote $quote)
    {
        $this->authorizeCompany($request, $quote);

        abort_if(
            in_array($quote->status, [Quote::STATUS_APPROVED, Quote::STATUS_REJECTED], true),
            422,
            'Orçamentos aprovados ou rejeitados não podem ser editados.'
        );

        $data = $this->validated($request);
        [$material, $printer] = $this->resolveEntities($request, $data);
        $productLines = $this->resolveProducts($request, $data);
        $setting = $request->user()->company->setting;

        $breakdown = $this->calculator->calculate($data, $material, $printer, $setting, $productLines);

        DB::transaction(function () use ($quote, $data, $breakdown) {
            $quote->update($this->quoteAttributes($data, $breakdown, $quote->status));
            $this->syncProductItems($quote, $breakdown);
        });

        return response()->json($quote->fresh()->load(['customer', 'printer', 'material', 'items.product']));
    }

    public function destroy(Request $request, Quote $quote)
    {
        $this->authorizeCompany($request, $quote);

        abort_if($quote->status === Quote::STATUS_APPROVED, 422, 'Orçamentos aprovados (vendas) não podem ser excluídos.');

        $quote->delete();

        return response()->json(null, 204);
    }

    public function approve(Request $request, Quote $quote)
    {
        $this->authorizeCompany($request, $quote);

        abort_unless($quote->status === Quote::STATUS_SENT, 422, 'Apenas orçamentos enviados podem ser aprovados ou rejeitados.');

        DB::transaction(function () use ($quote) {
            $productItems = $quote->items()
                ->where('type', 'product')
                ->whereNotNull('product_id')
                ->get();

            if ($productItems->isNotEmpty()) {
                $products = Product::whereIn('id', $productItems->pluck('product_id'))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($productItems as $item) {
                    $product = $products->get($item->product_id);
                    abort_unless($product, 422, "O produto \"{$item->description}\" não está mais cadastrado.");
                    abort_unless(
                        $product->stock_quantity >= $item->quantity,
                        422,
                        "Estoque insuficiente para \"{$product->name}\": disponível {$product->stock_quantity}, necessário {$item->quantity}."
                    );
                }

                foreach ($productItems as $item) {
                    $products[$item->product_id]->decrement('stock_quantity', $item->quantity);
                }
            }

            $quote->update(['status' => Quote::STATUS_APPROVED]);
        });

        return response()->json($quote->fresh()->load(['customer', 'printer', 'material', 'items.product']));
    }

    public function reject(Request $request, Quote $quote)
    {
        return $this->transitionStatus($request, $quote, Quote::STATUS_REJECTED);
    }

    private function transitionStatus(Request $request, Quote $quote, string $status)
    {
        $this->authorizeCompany($request, $quote);

        abort_unless($quote->status === Quote::STATUS_SENT, 422, 'Apenas orçamentos enviados podem ser aprovados ou rejeitados.');

        $quote->update(['status' => $status]);

        return response()->json($quote->fresh()->load(['customer', 'printer', 'material', 'items.product']));
    }

    private function quoteAttributes(array $data, array $breakdown, string $status): array
    {
        return array_merge($data, [
            'print_time_minutes' => (int) ($data['print_time_minutes'] ?? 0),
            'material_weight_g' => (float) ($data['material_weight_g'] ?? 0),
            'material_cost' => $breakdown['material_cost'],
            'energy_cost' => $breakdown['energy_cost'],
            'depreciation_cost' => $breakdown['depreciation_cost'],
            'labor_cost' => $breakdown['labor_cost'],
            'failure_rate_percent' => $breakdown['failure_rate_percent'],
            'failure_cost' => $breakdown['failure_cost'],
            'subtotal_cost' => $breakdown['subtotal_cost'],
            'markup_percent' => $breakdown['markup_percent'],
            'final_price' => $breakdown['final_price'],
            'unit_price' => $breakdown['unit_price'],
            'profit_amount' => $breakdown['profit_amount'],
            'status' => $status,
        ]);
    }

    private function syncProductItems(Quote $quote, array $breakdown): void
    {
        $quote->items()->where('type', 'product')->delete();

        foreach ($breakdown['products'] ?? [] as $line) {
            $quote->items()->create([
                'product_id' => $line['product_id'],
                'description' => $line['name'],
                'type' => 'product',
                'quantity' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
                'unit_price' => $line['unit_price'],
                'amount' => $line['line_total'],
            ]);
        }
    }

    private function resolveEntities(Request $request, array $data): array
    {
        $companyId = $request->user()->company_id;

        $material = isset($data['material_id'])
            ? Material::where('company_id', $companyId)->findOrFail($data['material_id'])
            : null;

        $printer = isset($data['printer_id'])
            ? Printer::where('company_id', $companyId)->findOrFail($data['printer_id'])
            : null;

        return [$material, $printer];
    }

    /**
     * @return array<int, array{product: Product, quantity: int}>
     */
    private function resolveProducts(Request $request, array $data): array
    {
        $lines = $data['products'] ?? [];
        if ($lines === []) {
            return [];
        }

        $products = Product::where('company_id', $request->user()->company_id)
            ->whereIn('id', collect($lines)->pluck('product_id'))
            ->get()
            ->keyBy('id');

        return collect($lines)->map(function (array $line) use ($products) {
            $product = $products->get($line['product_id']);
            abort_unless($product, 404, 'Produto não encontrado.');

            return ['product' => $product, 'quantity' => (int) $line['quantity']];
        })->all();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'printer_id' => ['nullable', 'exists:printers,id'],
            'material_id' => ['nullable', 'exists:materials,id'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'print_time_minutes' => ['required_without:products', 'nullable', 'integer', 'min:1'],
            'material_weight_g' => ['required_without:products', 'nullable', 'numeric', 'min:0'],
            'setup_minutes' => ['sometimes', 'integer', 'min:0'],
            'postprocess_minutes' => ['sometimes', 'integer', 'min:0'],
            'extra_costs' => ['sometimes', 'numeric', 'min:0'],
            'failure_rate_percent' => ['nullable', 'numeric', 'min:0'],
            'markup_percent' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['sometimes', 'numeric', 'min:0'],
            'products' => ['sometimes', 'array'],
            'products.*.product_id' => ['required', 'integer'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
    }

    private function authorizeCompany(Request $request, Quote $quote): void
    {
        abort_unless($quote->company_id === $request->user()->company_id, 403);
    }
}
