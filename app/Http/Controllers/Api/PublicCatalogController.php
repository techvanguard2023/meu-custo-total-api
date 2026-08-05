<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogVisit;
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
        // Aceita tanto o slug da empresa (link novo, legível — ex: minha-empresa-a1b2c3)
        // quanto o token aleatório antigo (mantido pra não quebrar links já compartilhados).
        $company = Company::where('slug', $token)->orWhere('catalog_token', $token)->first();

        // 404 tanto pra identificador inexistente quanto pra catálogo desligado/empresa
        // não-Pro — não dá pra distinguir os casos de fora.
        abort_unless($company && $company->hasCatalogActive(), 404, 'Catálogo não encontrado.');

        CatalogVisit::record($company, $request);

        $markup = (float) ($company->setting?->default_markup ?? 0);

        $products = $company->products()
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($product) use ($markup) {
                $cost = (float) $product->cost;
                $regularPrice = $product->sale_price !== null
                    ? (float) $product->sale_price
                    : round($cost * (1 + $markup / 100), 2);

                $discountPercent = $product->discount_percent !== null ? (float) $product->discount_percent : null;
                $promoPrice = $discountPercent
                    ? round($regularPrice * (1 - $discountPercent / 100), 2)
                    : null;

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'description' => $product->description,
                    'category_id' => $product->category_id,
                    'category_label' => $product->category?->name,
                    'image_url' => $product->image_url,
                    'images' => $product->images->map(fn ($image) => $image->image_url)->values(),
                    // Quando há promoção ativa, "price" já é o valor com desconto (é o que o
                    // cliente de fato paga — usado no carrinho, WhatsApp etc). O preço cheio
                    // riscado vem em "original_price", só quando aplicável.
                    'price' => $promoPrice ?? $regularPrice,
                    'original_price' => $promoPrice ? $regularPrice : null,
                    'discount_percent' => $promoPrice ? $discountPercent : null,
                    'stock_quantity' => (int) $product->stock_quantity,
                    'stock_status' => $this->stockStatus((int) $product->stock_quantity),
                    'made_to_order' => (bool) $product->made_to_order,
                    'featured' => (bool) $product->featured,
                ];
            });

        return response()->json([
            'company_name' => $company->name,
            'logo_url' => $company->logo_url,
            'whatsapp' => $company->catalog_whatsapp,
            'disclaimer' => $company->catalog_disclaimer,
            'social' => array_filter([
                'instagram_url' => $company->catalog_instagram_url,
                'facebook_url' => $company->catalog_facebook_url,
                'youtube_url' => $company->catalog_youtube_url,
                'tiktok_url' => $company->catalog_tiktok_url,
                'linkedin_url' => $company->catalog_linkedin_url,
            ]),
            'banners' => $company->banners->map(fn ($banner) => [
                'image_url' => $banner->image_url,
                'link_url' => $banner->link_url,
            ])->values(),
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
