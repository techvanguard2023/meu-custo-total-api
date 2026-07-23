<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;

/**
 * Catálogo público de produtos — sem autenticação. Nunca expõe custo,
 * margem ou qualquer outro dado sensível da empresa; só o necessário
 * para o cliente final ver o que está disponível.
 */
class PublicCatalogController extends Controller
{
    public function show(Request $request, string $token)
    {
        $company = Company::where('catalog_token', $token)->first();

        // 404 tanto pra token inexistente quanto pra catálogo desligado/empresa
        // não-Pro — não dá pra distinguir os casos de fora.
        abort_unless($company && $company->hasCatalogActive(), 404, 'Catálogo não encontrado.');

        $markup = (float) ($company->setting?->default_markup ?? 0);

        $products = $company->products()
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($product) use ($markup) {
                $cost = (float) $product->cost;
                $price = $product->sale_price !== null
                    ? (float) $product->sale_price
                    : round($cost * (1 + $markup / 100), 2);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'image_url' => $product->image_url,
                    'price' => $price,
                    'stock_status' => $this->stockStatus((int) $product->stock_quantity),
                ];
            });

        return response()->json([
            'company_name' => $company->name,
            'logo_url' => $company->logo_url,
            'products' => $products,
        ]);
    }

    private function stockStatus(int $quantity): string
    {
        return match (true) {
            $quantity <= 0 => 'out_of_stock',
            $quantity <= 2 => 'low_stock',
            default => 'in_stock',
        };
    }
}
