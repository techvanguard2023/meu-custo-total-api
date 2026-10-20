<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\Product;
use App\Services\MaterialStock;
use App\Services\ProductRecipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->company->materials()->with('category')->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $material = $request->user()->company->materials()->create($data);

        if ($material->stock_quantity !== null && (float) $material->stock_quantity != 0) {
            MaterialStock::record($material, (float) $material->stock_quantity, MaterialMovement::TYPE_ADJUSTMENT, ['note' => 'Estoque inicial']);
        }

        return response()->json($material->load('category'), 201);
    }

    public function show(Request $request, Material $material)
    {
        $this->authorizeCompany($request, $material);

        return $material->load('category');
    }

    public function update(Request $request, Material $material)
    {
        $this->authorizeCompany($request, $material);

        DB::transaction(function () use ($request, $material) {
            $before = $material->stock_quantity;
            $material->update($this->validated($request));

            // Edição manual do saldo (contagem, compra, perda) — entra no histórico como ajuste.
            // Passar a não controlar o estoque (vazio) não gera movimentação.
            if ($material->stock_quantity !== null && (float) $material->stock_quantity != (float) ($before ?? 0)) {
                MaterialStock::record(
                    $material,
                    (float) $material->stock_quantity - (float) ($before ?? 0),
                    MaterialMovement::TYPE_ADJUSTMENT,
                    ['note' => $before === null ? 'Estoque inicial' : 'Ajuste manual'],
                );
            }

            if ($material->wasChanged(['cost_per_g', 'material_category_id'])) {
                app(ProductRecipe::class)->refreshForMaterial($material);
            }
        });

        return $material->load('category');
    }

    /** Histórico de entradas e saídas do insumo, mais recentes primeiro. */
    public function movements(Request $request, Material $material)
    {
        $this->authorizeCompany($request, $material);

        return $material->movements()
            ->with(['product:id,name', 'variation:id,color,size,weight', 'user:id,name'])
            ->orderByDesc('id')
            ->paginate(30);
    }

    public function destroy(Request $request, Material $material)
    {
        $this->authorizeCompany($request, $material);

        // A linha da ficha técnica some junto com o insumo — o custo dos produtos que o usavam muda.
        $affected = Product::whereHas('recipeLines', fn ($q) => $q->where('material_id', $material->id))->get();
        $material->delete();
        $affected->each(fn (Product $product) => app(ProductRecipe::class)->refreshCost($product));

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        $companyId = $request->user()->company_id;

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'material_category_id' => [
                'nullable',
                Rule::exists('material_categories', 'id')
                    ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId)),
            ],
            'color' => ['nullable', 'string', 'max:255'],
            'pantone_code' => ['nullable', 'string', 'max:20'],
            'hex_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'spool_weight_g' => ['required', 'integer', 'min:1'],
            'spool_cost' => ['required', 'numeric', 'min:0'],
            'cost_per_g' => ['required', 'numeric', 'min:0'],
            'density' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'numeric', 'min:0'],
            'purchase_url' => ['nullable', 'url', 'max:2048'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function authorizeCompany(Request $request, Material $material): void
    {
        abort_unless($material->company_id === $request->user()->company_id, 403);
    }
}
