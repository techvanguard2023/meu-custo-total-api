<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use EnforcesPlanLimits;

    /** Caracteres sem ambiguidade visual (sem 0/O, 1/I/L) pra código de produto legível. */
    private const SKU_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const SKU_LENGTH = 6;

    public function index(Request $request)
    {
        return $request->user()->company->products()->with('collections:id,name')->latest()->get();
    }

    /** Sugere um código de produto único (dentro da empresa) pra preencher o campo SKU. */
    public function generateSku(Request $request)
    {
        return response()->json(['sku' => $this->generateUniqueSku($request->user()->company_id)]);
    }

    private function generateUniqueSku(int $companyId): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::SKU_LENGTH; $i++) {
                $code .= self::SKU_ALPHABET[random_int(0, strlen(self::SKU_ALPHABET) - 1)];
            }

            $exists = Product::where('company_id', $companyId)->where('sku', $code)->exists();
            if (! $exists) {
                return $code;
            }
        }

        throw new \RuntimeException('Não foi possível gerar um código único de produto.');
    }

    /** Slug único por empresa (não globalmente) — duas lojas podem ter cada uma o seu "vaso-azul". */
    private function generateUniqueSlug(string $name, int $companyId): string
    {
        $base = Str::slug($name) ?: 'produto';
        $slug = $base;

        for ($suffix = 2; $this->slugConflicts($slug, $companyId); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Produto e categoria dividem o mesmo espaço de nomes na URL do catálogo —
     * o último segmento do link pode ser de qualquer um dos dois — então
     * nenhum pode repetir o slug do outro dentro da mesma empresa.
     */
    private function slugConflicts(string $slug, int $companyId): bool
    {
        return Product::where('company_id', $companyId)->where('slug', $slug)->exists()
            || ProductCategory::visibleTo($companyId)->where('slug', $slug)->exists();
    }

    public function store(Request $request)
    {
        $this->enforceFreeLimit($request, 'products', $request->user()->company->products()->count(), 'produtos');

        $data = $this->validated($request, $request->user()->company_id);
        $variations = $data['variations'] ?? null;
        $collectionIds = $data['collection_ids'] ?? null;
        unset($data['variations'], $data['collection_ids']);

        // Gerado uma vez, aqui — nunca a partir do input do cliente, e nunca
        // regerado depois. Se mudasse junto do nome, todo link já compartilhado
        // (WhatsApp, Instagram) apontando pra esse produto quebraria.
        $data['slug'] = $this->generateUniqueSlug($data['name'], $request->user()->company_id);

        $product = DB::transaction(function () use ($request, $data, $variations, $collectionIds) {
            $product = $request->user()->company->products()->create($data);
            $this->syncVariations($product, $variations);
            $this->syncCollections($product, $collectionIds);

            return $product;
        });

        return response()->json($product->fresh(), 201);
    }

    public function show(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);

        return $product->load('collections:id,name');
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);

        $data = $this->validated($request, $product->company_id, $product->id);
        $variations = $data['variations'] ?? null;
        $collectionIds = $data['collection_ids'] ?? null;
        unset($data['variations'], $data['collection_ids']);

        DB::transaction(function () use ($product, $data, $variations, $collectionIds) {
            $product->update($data);
            $this->syncVariations($product, $variations);
            $this->syncCollections($product, $collectionIds);
        });

        return $product->fresh()->load('collections:id,name');
    }

    /**
     * $collectionIds === null significa "campo não enviado" — mantém como está,
     * mesmo padrão de $variations === null em syncVariations().
     */
    private function syncCollections(Product $product, ?array $collectionIds): void
    {
        if ($collectionIds === null) {
            return;
        }

        // Só coleções da própria empresa — sem isso daria pra marcar um produto
        // numa coleção de outra empresa.
        $ownIds = $product->company->productCollections()->whereIn('id', $collectionIds)->pluck('id');
        $product->collections()->sync($ownIds);
    }

    /**
     * Sincroniza as variações enviadas: atualiza as que já existem (pelo id),
     * cria as novas e remove as que sumiram do formulário.
     *
     * Atualizar em vez de recriar é essencial: quote_items apontam para a
     * variação, então apagar e recriar quebraria o vínculo do histórico de vendas
     * e, com nullOnDelete, faria a venda perder de qual variação ela era.
     *
     * $variations === null significa "campo não enviado" — mantém como está.
     */
    private function syncVariations(Product $product, ?array $variations): void
    {
        if ($variations === null) {
            return;
        }

        $keptIds = [];

        foreach (array_values($variations) as $position => $row) {
            $payload = [
                'color' => $row['color'] ?? null,
                'size' => $row['size'] ?? null,
                'weight' => $row['weight'] ?? null,
                'sku' => $row['sku'] ?? null,
                'sale_price' => $row['sale_price'] ?? null,
                'cost' => $row['cost'] ?? null,
                'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
                'active' => $row['active'] ?? true,
                'position' => $position,
            ];

            $existing = isset($row['id'])
                ? $product->variations()->whereKey($row['id'])->first()
                : null;

            if ($existing) {
                $existing->update($payload);
                $keptIds[] = $existing->id;
            } else {
                $keptIds[] = $product->variations()->create($payload)->id;
            }
        }

        $product->variations()->whereNotIn('id', $keptIds ?: [0])->delete();
        $product->unsetRelation('variations');
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);

        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->path);
        }

        $product->delete();

        return response()->noContent();
    }

    /** Upload de uma ou mais fotos, adicionadas ao final da galeria (máx. 8 por produto). */
    public function uploadImages(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);
        $this->requirePro($request, 'Fotos do produto');

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $existingCount = $product->images()->count();
        abort_if($existingCount + count($request->file('images')) > 8, 422, 'Máximo de 8 fotos por produto.');

        $nextPosition = $existingCount;
        foreach ($request->file('images') as $file) {
            $path = $file->store('products', 'public');
            $product->images()->create(['path' => $path, 'position' => $nextPosition++]);
        }

        return response()->json($product->fresh());
    }

    public function destroyImage(Request $request, Product $product, ProductImage $image)
    {
        $this->authorizeCompany($request, $product);
        abort_unless($image->product_id === $product->id, 404);

        Storage::disk('public')->delete($image->path);
        $image->delete();

        return response()->json($product->fresh());
    }

    /** Reordena a galeria — recebe a lista de IDs das fotos na nova ordem. */
    public function reorderImages(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);

        $data = $request->validate([
            'image_ids' => ['required', 'array'],
            'image_ids.*' => ['integer'],
        ]);

        $ids = $product->images()->pluck('id')->all();
        abort_unless(count(array_diff($ids, $data['image_ids'])) === 0 && count($data['image_ids']) === count($ids), 422, 'Lista de fotos inválida.');

        foreach ($data['image_ids'] as $position => $imageId) {
            ProductImage::where('id', $imageId)->where('product_id', $product->id)->update(['position' => $position]);
        }

        return response()->json($product->fresh());
    }

    private function validated(Request $request, int $companyId, ?int $ignoreProductId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'nullable', 'string', 'max:255',
                Rule::unique('products', 'sku')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($ignoreProductId),
            ],
            'description' => ['nullable', 'string'],
            // Só as padrão do sistema e as da própria empresa — sem isso daria para
            // apontar um produto para a categoria privada de outra empresa.
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('product_categories', 'id')
                    ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId)),
            ],
            'model_3d_url' => ['nullable', 'url', 'max:2048'],
            'cost' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'made_to_order' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'discount_percent' => ['nullable', 'numeric', 'min:0.01', 'max:95'],
            'active' => ['sometimes', 'boolean'],

            'collection_ids' => ['sometimes', 'array'],
            'collection_ids.*' => ['integer'],

            'variations' => ['sometimes', 'array', 'max:50'],
            'variations.*.id' => ['sometimes', 'nullable', 'integer'],
            // Ao menos um atributo precisa vir preenchido, senão a variação não
            // significa nada pra quem está comprando.
            'variations.*.color' => ['nullable', 'string', 'max:60', 'required_without_all:variations.*.size,variations.*.weight'],
            'variations.*.size' => ['nullable', 'string', 'max:60'],
            'variations.*.weight' => ['nullable', 'string', 'max:60'],
            'variations.*.sku' => ['nullable', 'string', 'max:255'],
            'variations.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'variations.*.cost' => ['nullable', 'numeric', 'min:0'],
            'variations.*.stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'variations.*.active' => ['sometimes', 'boolean'],
        ], [
            'sku.unique' => 'Este código já está em uso por outro produto.',
            'variations.*.color.required_without_all' => 'Informe ao menos cor, tamanho ou peso na variação.',
        ]);
    }

    private function authorizeCompany(Request $request, Product $product): void
    {
        abort_unless($product->company_id === $request->user()->company_id, 403);
    }
}
