<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use EnforcesPlanLimits;

    /** Caracteres sem ambiguidade visual (sem 0/O, 1/I/L) pra código de produto legível. */
    private const SKU_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const SKU_LENGTH = 6;

    public function index(Request $request)
    {
        return $request->user()->company->products()->latest()->get();
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

    public function store(Request $request)
    {
        $this->enforceFreeLimit($request, 'products', $request->user()->company->products()->count(), 'produtos');

        $data = $this->validated($request, $request->user()->company_id);
        $product = $request->user()->company->products()->create($data);

        return response()->json($product->fresh(), 201);
    }

    public function show(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);

        return $product;
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);
        $product->update($this->validated($request, $product->company_id, $product->id));

        return $product->fresh();
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
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')],
            'model_3d_url' => ['nullable', 'url', 'max:2048'],
            'cost' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'made_to_order' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'sku.unique' => 'Este código já está em uso por outro produto.',
        ]);
    }

    private function authorizeCompany(Request $request, Product $product): void
    {
        abort_unless($product->company_id === $request->user()->company_id, 403);
    }
}
