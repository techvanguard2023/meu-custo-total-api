<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\ProductCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Coleção de campanha (Natal, Réveillon, Volta às Aulas...) — recurso Pro, no
 * mesmo espírito do catálogo público: página própria e temporária reunindo
 * produtos de qualquer categoria, ligada/desligada manualmente pelo lojista.
 */
class ProductCollectionController extends Controller
{
    use EnforcesPlanLimits;

    public function index(Request $request)
    {
        return $request->user()->company->productCollections()
            ->withCount('products')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $this->requirePro($request, 'Coleções de campanha');

        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('product_collections', 'name')->where('company_id', $companyId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'Já existe uma coleção com esse nome.',
        ]);

        $collection = ProductCollection::create([
            'company_id' => $companyId,
            'name' => trim($data['name']),
            // Gerado uma vez, aqui — nunca a partir do input do cliente, e nunca
            // regerado ao renomear (senão todo link de coleção já compartilhado quebraria).
            'slug' => $this->generateUniqueSlug(trim($data['name']), $companyId),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'active' => $data['active'] ?? false,
        ]);

        return response()->json($collection, 201);
    }

    public function update(Request $request, ProductCollection $productCollection)
    {
        $this->requirePro($request, 'Coleções de campanha');
        $this->authorizeCompany($request, $productCollection);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('product_collections', 'name')
                    ->where('company_id', $productCollection->company_id)
                    ->ignore($productCollection->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'Já existe uma coleção com esse nome.',
        ]);

        $productCollection->name = trim($data['name']);
        // 'sometimes': descrição só muda se o campo veio na requisição — uma
        // chamada que só liga/desliga a coleção (active) não pode apagá-la.
        if (array_key_exists('description', $data)) {
            $productCollection->description = $data['description'] !== null ? trim($data['description']) : null;
        }
        if (array_key_exists('active', $data)) {
            $productCollection->active = $data['active'];
        }
        $productCollection->save();

        return response()->json($productCollection);
    }

    public function destroy(Request $request, ProductCollection $productCollection)
    {
        $this->authorizeCompany($request, $productCollection);

        // Excluir a coleção não afeta os produtos — eles mantêm a categoria real,
        // só deixam de aparecer nessa vitrine temporária.
        if ($productCollection->banner_path) {
            Storage::disk('public')->delete($productCollection->banner_path);
        }

        $productCollection->delete();

        return response()->json(null, 204);
    }

    /** Banner exibido no topo da página da coleção e no card de destaque no catálogo. */
    public function uploadBanner(Request $request, ProductCollection $productCollection)
    {
        $this->requirePro($request, 'Coleções de campanha');
        $this->authorizeCompany($request, $productCollection);

        $request->validate([
            'banner' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'banner.max' => 'A imagem deve ter no máximo 4MB.',
            'banner.image' => 'Envie um arquivo de imagem válido (JPG, PNG ou WebP).',
            'banner.mimes' => 'Formato não suportado — envie JPG, PNG ou WebP.',
        ]);

        if ($productCollection->banner_path) {
            Storage::disk('public')->delete($productCollection->banner_path);
        }

        $path = $request->file('banner')->store('collections', 'public');
        $productCollection->update(['banner_path' => $path]);

        return response()->json($productCollection->fresh());
    }

    public function destroyBanner(Request $request, ProductCollection $productCollection)
    {
        $this->authorizeCompany($request, $productCollection);

        if ($productCollection->banner_path) {
            Storage::disk('public')->delete($productCollection->banner_path);
            $productCollection->update(['banner_path' => null]);
        }

        return response()->json($productCollection->fresh());
    }

    /** Slug único por empresa — coleção não tem conceito de "padrão do sistema". */
    private function generateUniqueSlug(string $name, int $companyId): string
    {
        $base = Str::slug($name) ?: 'colecao';
        $slug = $base;

        for ($suffix = 2; ProductCollection::where('company_id', $companyId)->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    private function authorizeCompany(Request $request, ProductCollection $collection): void
    {
        abort_unless($collection->company_id === $request->user()->company_id, 403);
    }
}
