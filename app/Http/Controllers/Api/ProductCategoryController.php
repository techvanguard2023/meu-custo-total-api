<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Categorias de produto em dois níveis: as padrão do sistema (company_id nulo,
 * iguais para todas as empresas) mais as que cada empresa cria para si, e as
 * subcategorias, que são sempre da empresa — mesmo penduradas numa padrão.
 *
 * As padrão não podem ser editadas nem removidas: são compartilhadas, e mexer
 * nelas afetaria todas as outras empresas.
 */
class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        return ProductCategory::visibleTo($request->user()->company_id)
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                $this->uniqueName($companyId, $this->intOrNull($request->input('parent_id'))),
            ],
            'parent_id' => ['nullable', 'integer'],
        ], [
            'name.unique' => 'Já existe uma categoria com esse nome aqui.',
        ]);

        $parentId = $this->resolveParentId($this->intOrNull($data['parent_id'] ?? null), $companyId);

        $category = ProductCategory::create([
            // Subcategoria é sempre da empresa, mesmo quando o pai é uma padrão
            'company_id' => $companyId,
            'parent_id' => $parentId,
            'name' => trim($data['name']),
        ]);

        return response()->json($category, 201);
    }

    public function update(Request $request, ProductCategory $productCategory)
    {
        $companyId = $this->authorizeOwnCategory($request, $productCategory);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                $this->uniqueName($companyId, $productCategory->parent_id)->ignore($productCategory->id),
            ],
        ], [
            'name.unique' => 'Já existe uma categoria com esse nome aqui.',
        ]);

        $productCategory->update(['name' => trim($data['name'])]);

        return response()->json($productCategory);
    }

    public function destroy(Request $request, ProductCategory $productCategory)
    {
        $this->authorizeOwnCategory($request, $productCategory);

        // Excluir uma categoria em uso deixaria os produtos sem classificação
        // silenciosamente — melhor barrar e dizer quantos estão usando.
        $inUse = $productCategory->products()->count();
        abort_if(
            $inUse > 0,
            422,
            "Esta categoria está em uso por {$inUse} produto".($inUse > 1 ? 's' : '').'. Troque a categoria deles antes de excluir.'
        );

        // O cascade do banco apagaria as filhas em silêncio; melhor avisar.
        $children = $productCategory->children()->count();
        abort_if(
            $children > 0,
            422,
            "Esta categoria tem {$children} subcategoria".($children > 1 ? 's' : '').'. Exclua-as antes.'
        );

        $productCategory->delete();

        return response()->json(null, 204);
    }

    /**
     * Nome único dentro do mesmo pai — "Carros" pode existir em Chaveiros e em
     * Miniaturas ao mesmo tempo, mas não duas vezes no mesmo lugar.
     */
    private function uniqueName(int $companyId, ?int $parentId)
    {
        return Rule::unique('product_categories', 'name')
            ->where(function ($query) use ($companyId, $parentId) {
                $query
                    ->where(fn ($w) => $w->whereNull('company_id')->orWhere('company_id', $companyId))
                    ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'))
                    ->when($parentId !== null, fn ($q) => $q->where('parent_id', $parentId));
            });
    }

    private function intOrNull($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** Valida o pai informado: precisa ser visível à empresa e ser raiz (só dois níveis). */
    private function resolveParentId(?int $parentId, int $companyId): ?int
    {
        if ($parentId === null) {
            return null;
        }

        $parent = ProductCategory::visibleTo($companyId)->find($parentId);

        abort_unless($parent, 422, 'Categoria principal não encontrada.');
        abort_if($parent->parent_id !== null, 422, 'Só é possível criar subcategorias dentro de uma categoria principal.');

        return $parent->id;
    }

    /** Só a dona da categoria mexe nela — e nunca nas padrão do sistema. */
    private function authorizeOwnCategory(Request $request, ProductCategory $category): int
    {
        $companyId = $request->user()->company_id;

        abort_if($category->company_id === null, 422, 'As categorias padrão do sistema não podem ser alteradas.');
        abort_unless($category->company_id === $companyId, 403);

        return $companyId;
    }
}
