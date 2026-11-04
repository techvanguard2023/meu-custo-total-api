<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/** Achadinho: produto de outra loja divulgado com o link de afiliado da pessoa (Ofertas → Achadinhos, Pro). */
class AffiliateOffer extends Model
{
    public const MARKETPLACES = [
        'mercadolivre' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'amazon' => 'Amazon',
        'magalu' => 'Magalu',
        'aliexpress' => 'AliExpress',
        'shein' => 'Shein',
        'other' => 'Outra loja',
    ];

    /** Pra frase "por R$ 89,90 no Mercado Livre" / "na Shopee". */
    private const PREPOSITIONS = ['mercadolivre' => 'no', 'aliexpress' => 'no'];

    /** Domínios (inclusive os de link curto de afiliado) de cada loja. */
    private const HOSTS = [
        'mercadolivre' => ['mercadolivre.com', 'mercadolibre.com', 'mercadolivre.com.br', 'meli.la'],
        'shopee' => ['shopee.com.br', 'shopee.com', 'shp.ee'],
        'amazon' => ['amazon.com.br', 'amazon.com', 'amzn.to', 'a.co', 'amzn.com'],
        'magalu' => ['magazineluiza.com.br', 'magalu.com', 'magazinevoce.com.br', 'divulgador.magalu.com'],
        'aliexpress' => ['aliexpress.com', 'aliexpress.us', 's.click.aliexpress.com', 'a.aliexpress.com'],
        'shein' => ['shein.com', 'shein.com.br', 'onelink.shein.com'],
    ];

    protected $fillable = [
        'company_id', 'marketplace', 'url', 'title', 'price', 'original_price', 'store_coupon',
        'offer_text', 'image_path', 'expires_at', 'active', 'last_sent_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'expires_at' => 'datetime',
        'active' => 'boolean',
        'last_sent_at' => 'datetime',
    ];

    protected $appends = ['image_url', 'marketplace_label', 'expired'];

    public static function detectMarketplace(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::HOSTS as $key => $domains) {
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $key;
                }
            }
        }

        return 'other';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(OfferPost::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function getMarketplaceLabelAttribute(): string
    {
        return self::MARKETPLACES[$this->marketplace] ?? self::MARKETPLACES['other'];
    }

    public function getExpiredAttribute(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** "no Mercado Livre", "na Shopee" — vazio pra "Outra loja". */
    public function whereToBuy(): string
    {
        if ($this->marketplace === 'other' || ! isset(self::MARKETPLACES[$this->marketplace])) {
            return '';
        }

        return (self::PREPOSITIONS[$this->marketplace] ?? 'na').' '.self::MARKETPLACES[$this->marketplace];
    }

    /** Pode sair agora? (ligado e na validade) — o motivo quando não pode. */
    public function unavailableReason(): ?string
    {
        if (! $this->active) {
            return 'Achadinho desligado';
        }

        return $this->expired ? 'Oferta vencida' : null;
    }
}
