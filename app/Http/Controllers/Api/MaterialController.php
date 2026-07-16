<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->company->materials()->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $material = $request->user()->company->materials()->create($data);

        return response()->json($material, 201);
    }

    public function show(Request $request, Material $material)
    {
        $this->authorizeCompany($request, $material);

        return $material;
    }

    public function update(Request $request, Material $material)
    {
        $this->authorizeCompany($request, $material);
        $material->update($this->validated($request));

        return $material;
    }

    public function destroy(Request $request, Material $material)
    {
        $this->authorizeCompany($request, $material);
        $material->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:255'],
            'spool_weight_g' => ['required', 'integer', 'min:1'],
            'spool_cost' => ['required', 'numeric', 'min:0'],
            'cost_per_g' => ['required', 'numeric', 'min:0'],
            'density' => ['nullable', 'numeric', 'min:0'],
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
