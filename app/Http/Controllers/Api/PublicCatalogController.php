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

        // Depoimentos sobre a loja como um todo — de vendas sob encomenda, sem
        // produto de catálogo vinculado, então não cabem no card de um produto.
        $storeReviews = $company->productReviews()->whereNull('product_id');
        $storeRatingAvg = (clone $storeReviews)->avg('rating');
        $storeRatingCount = (clone $storeReviews)->count();
        $testimonials = (clone $storeReviews)
            ->withApprovedComment()
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn ($review) => [
                'rating' => $review->rating,
                'comment' => $review->comment,
                'reviewer_name' => $review->reviewer_first_name,
                'created_at' => $review->created_at?->toIso8601String(),
            ])
            ->values();

        $products = $company->products()
            ->where('active', true)
            ->orderBy('name')
            ->with('category.parent')
            // Uma consulta só pra média/contagem e outra pros comentários
            // aprovados — sem isso seria uma query por produto no map abaixo.
            ->withAvg('reviews as rating_avg', 'rating')
            ->withCount('reviews as rating_count')
            ->with(['reviews' => fn ($q) => $q->withApprovedComment()->latest()->limit(20)])
            ->get()
            ->map(function ($product) use ($markup) {
                $cost = (float) $product->cost;
                $regularPrice = $product->sale_price !== null
                    ? (float) $product->sale_price
                    : round($cost * (1 + $markup / 100), 2);

                $discountPercent = $product->discount_percent !== null ? (float) $product->discount_percent : null;
                $applyDiscount = fn (float $price) => $discountPercent
                    ? round($price * (1 - $discountPercent / 100), 2)
                    : null;

                $promoPrice = $applyDiscount($regularPrice);

                // Só variações ativas aparecem para o cliente. O desconto do produto
                // vale para todas — inclusive as que têm preço próprio.
                $variations = $product->variations
                    ->where('active', true)
                    ->map(function ($variation) use ($product, $markup, $applyDiscount) {
                        $variationCost = $variation->effectiveCost($product);
                        $variationPrice = $variation->effectivePrice($product)
                            ?? round($variationCost * (1 + $markup / 100), 2);
                        $variationPromo = $applyDiscount($variationPrice);

                        return [
                            'id' => $variation->id,
                            'label' => $variation->display_name,
                            'color' => $variation->color,
                            'size' => $variation->size,
                            'weight' => $variation->weight,
                            'price' => $variationPromo ?? $variationPrice,
                            'original_price' => $variationPromo ? $variationPrice : null,
                            'stock_quantity' => (int) $variation->stock_quantity,
                            'stock_status' => $this->stockStatus((int) $variation->stock_quantity),
                        ];
                    })
                    ->values();

                // Com variações, o estoque exibido é a soma das ativas e o preço do card
                // é o da variação mais barata (padrão de e-commerce: "a partir de").
                $hasVariations = $variations->isNotEmpty();
                $stock = $hasVariations ? (int) $variations->sum('stock_quantity') : (int) $product->stock_quantity;

                if ($hasVariations) {
                    $cheapest = $variations->sortBy('price')->first();
                    $promoPrice = $cheapest['original_price'] ? $cheapest['price'] : null;
                    $regularPrice = $cheapest['original_price'] ?? $cheapest['price'];
                }

                return [
                    'id' => $product->id,
                    'slug' => $product->slug,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'description' => $product->description,
                    // A categoria do produto pode ser uma raiz (Chaveiros) ou uma subcategoria
                    // (Carros, dentro de Chaveiros). O front usa root_id/root_label pra agrupar
                    // o filtro em dois níveis e pra não confundir subcategorias homônimas de
                    // pais diferentes (ex: "Carros" em Chaveiros e "Carros" em Miniaturas).
                    'category_id' => $product->category_id,
                    'category_label' => $product->category?->name,
                    // Slugs estáveis (nunca mudam ao renomear) — usados pra montar e
                    // resolver a URL de categoria (/catalog/loja/chaveiros/carros).
                    'category_slug' => $product->category?->slug,
                    'category_root_id' => $product->category?->parent_id ?? $product->category_id,
                    'category_root_label' => $product->category?->parent?->name ?? $product->category?->name,
                    'category_root_slug' => $product->category?->parent?->slug ?? $product->category?->slug,
                    'image_url' => $product->image_url,
                    'images' => $product->images->map(fn ($image) => $image->image_url)->values(),
                    // Quando há promoção ativa, "price" já é o valor com desconto (é o que o
                    // cliente de fato paga — usado no carrinho, WhatsApp etc). O preço cheio
                    // riscado vem em "original_price", só quando aplicável.
                    'price' => $promoPrice ?? $regularPrice,
                    'original_price' => $promoPrice ? $regularPrice : null,
                    'discount_percent' => $promoPrice ? $discountPercent : null,
                    'stock_quantity' => $stock,
                    'stock_status' => $this->stockStatus($stock),
                    'made_to_order' => (bool) $product->made_to_order,
                    'featured' => (bool) $product->featured,
                    // Média das notas + quantas avaliações — o card mostra as duas
                    // juntas, pra "5,0" com uma única avaliação não parecer consenso.
                    'rating_avg' => $product->rating_avg !== null ? round((float) $product->rating_avg, 1) : null,
                    'rating_count' => (int) $product->rating_count,
                    // Só comentários liberados pelo lojista chegam aqui.
                    'reviews' => $product->reviews->map(fn ($review) => [
                        'rating' => $review->rating,
                        'comment' => $review->comment,
                        'reviewer_name' => $review->reviewer_first_name,
                        'created_at' => $review->created_at?->toIso8601String(),
                    ])->values(),
                    'variations' => $variations,
                ];
            });

        return response()->json([
            'company_name' => $company->name,
            'logo_url' => $company->logo_url,
            'whatsapp' => $company->catalog_whatsapp,
            'disclaimer' => $company->catalog_disclaimer,
            'about' => $company->catalog_about,
            // Só a cor de destaque (botões, badges, links) — fundo e texto
            // continuam neutros, pra não arriscar contraste ilegível.
            'accent_color' => $company->catalog_accent_color,
            // Nota geral da loja + depoimentos — só de vendas sem produto de
            // catálogo vinculado (o resto já aparece no card de cada produto).
            'store_rating_avg' => $storeRatingAvg !== null ? round((float) $storeRatingAvg, 1) : null,
            'store_rating_count' => $storeRatingCount,
            'testimonials' => $testimonials,
            // Só o que o dono preencheu explicitamente para ser público — o menu
            // "Contatos" some quando nada aqui foi informado.
            'contact' => array_filter([
                'address' => $company->catalog_address,
                'hours' => $company->catalog_hours,
                'email' => $company->catalog_email,
            ]),
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
            // Coleções de campanha (Natal, Réveillon...) ativas e com pelo menos um
            // produto ativo — o dono liga/desliga manualmente em Configurações.
            'collections' => $company->productCollections()
                ->where('active', true)
                ->with(['products' => fn ($q) => $q->where('active', true)])
                ->get()
                ->filter(fn ($c) => $c->products->isNotEmpty())
                ->map(fn ($c) => [
                    'slug' => $c->slug,
                    'name' => $c->name,
                    'description' => $c->description,
                    'banner_url' => $c->banner_url,
                    'product_ids' => $c->products->pluck('id')->values(),
                ])
                ->values(),
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
