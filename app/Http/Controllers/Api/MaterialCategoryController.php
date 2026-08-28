<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Categoria de material: as padrão do sistema (company_id nulo, iguais para
 * todas as empresas) mais as que cada empresa cria pra si — sem hierarquia.
 * A unidade de preço (peso/unidade) é travada na criação: não pode ser
 * editada depois, porque mudaria retroativamente o cálculo de todo material
 * já cadastrado na categoria.
 */
class MaterialCategoryController extends Controller
{
    public function index(Request $request)
    {
        return MaterialCategory::visibleTo($request->user()->company_id)
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', $this->uniqueName($companyId)],
            'pricing_unit' => ['required', Rule::in([MaterialCategory::PRICING_WEIGHT, MaterialCategory::PRICING_UNIT])],
        ], [
            'name.unique' => 'Já existe uma categoria com esse nome aqui.',
        ]);

        $category = MaterialCategory::create([
            'company_id' => $companyId,
            'name' => trim($data['name']),
            'pricing_unit' => $data['pricing_unit'],
        ]);

        return response()->json($category, 201);
    }

    public function update(Request $request, MaterialCategory $materialCategory)
    {
        $companyId = $this->authorizeOwnCategory($request, $materialCategory);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', $this->uniqueName($companyId)->ignore($materialCategory->id)],
        ], [
            'name.unique' => 'Já existe uma categoria com esse nome aqui.',
        ]);

        $materialCategory->update(['name' => trim($data['name'])]);

        return response()->json($materialCategory);
    }

    public function destroy(Request $request, MaterialCategory $materialCategory)
    {
        $this->authorizeOwnCategory($request, $materialCategory);

        // Excluir uma categoria em uso deixaria o material sem classificação
        // silenciosamente — melhor barrar e dizer quantos estão usando.
        $inUse = $materialCategory->materials()->count();
        abort_if(
            $inUse > 0,
            422,
            "Esta categoria está em uso por {$inUse} ".($inUse > 1 ? 'materiais' : 'material').'. Troque a categoria deles antes de excluir.'
        );

        $materialCategory->delete();

        return response()->json(null, 204);
    }

    private function uniqueName(int $companyId)
    {
        return Rule::unique('material_categories', 'name')
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    /** Só a dona da categoria mexe nela — e nunca nas padrão do sistema. */
    private function authorizeOwnCategory(Request $request, MaterialCategory $category): int
    {
        $companyId = $request->user()->company_id;

        abort_if($category->company_id === null, 422, 'As categorias padrão do sistema não podem ser alteradas.');
        abort_unless($category->company_id === $companyId, 403);

        return $companyId;
    }
}
