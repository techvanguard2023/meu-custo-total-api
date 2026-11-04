<?php

namespace App\Services;

use App\Models\AffiliateAccount;
use App\Models\AffiliateOffer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Preço atual do achadinho pela API da loja de origem (hoje só Shopee, com a conta conectada), conferido antes
 * de enviar — no máximo uma consulta por hora por achadinho (vários grupos recebendo a mesma oferta não repetem).
 * Falha na API nunca impede o envio: a oferta sai com o último preço salvo.
 */
class AffiliatePriceRefresher
{
    private const MAX_AGE_MINUTES = 60;

    public function refresh(AffiliateOffer $offer): void
    {
        if ($offer->marketplace !== 'shopee' || ! preg_match('/^shopee:(\d+)-(\d+)$/', (string) $offer->product_key, $m)) {
            return;
        }
        if ($offer->price_checked_at && $offer->price_checked_at->gt(now()->subMinutes(self::MAX_AGE_MINUTES))) {
            return;
        }
        $account = AffiliateAccount::shopeeFor($offer->company_id);
        if (! $account?->verified_at) {
            return;
        }

        try {
            $item = ShopeeAffiliate::for($account)->item($m[1], $m[2]);
        } catch (Throwable $e) {
            Log::info("Achadinhos: preço da Shopee não conferido (achadinho {$offer->id}): ".$e->getMessage());

            return;
        }

        // Item fora das ofertas da Shopee: mantém o preço salvo, só marca a conferência
        $offer->forceFill($item ? [
            'price' => $item['price'] ?? $offer->price,
            // Desconto acabou: o "de" riscado sai
            'original_price' => $item['original_price'],
            'commission_rate' => $item['commission_rate'] ?? $offer->commission_rate,
            'price_checked_at' => now(),
        ] : ['price_checked_at' => now()])->save();
    }
}
