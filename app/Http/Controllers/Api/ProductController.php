<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->company->products()->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $product = $request->user()->company->products()->create($data);

        return response()->json($product, 201);
    }

    public function show(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);

        return $product;
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);
        $product->update($this->validated($request));

        return $product;
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);
        $product->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'cost' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function authorizeCompany(Request $request, Product $product): void
    {
        abort_unless($product->company_id === $request->user()->company_id, 403);
    }
}
