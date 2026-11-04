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
        'company_id', 'marketplace', 'url', 'source_url', 'product_key', 'title', 'price', 'original_price', 'store_coupon',
        'offer_text', 'description', 'image_path', 'expires_at', 'flash', 'active', 'last_sent_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'expires_at' => 'datetime',
        'active' => 'boolean',
        'flash' => 'boolean',
        'last_sent_at' => 'datetime',
    ];

    /** Fora da resposta da tela: são só pra achar repetido e pra IA. */
    protected $hidden = ['product_key', 'description'];

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

    /**
     * "Identidade" do produto, pra avisar quando ele já foi cadastrado — mesmo com outro link curto de afiliado:
     * ASIN na Amazon, MLB no Mercado Livre, loja+item na Shopee; nas outras, o endereço sem os parâmetros.
     */
    public static function productKey(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $patterns = [
            'amazon' => '~/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})~i',
            'mercadolivre' => '~\b(MLB)-?(\d{6,})~i',
            'shopee' => '~(?:-i\.|/product/)(\d+)[./](\d+)~',
        ];
        $marketplace = self::detectMarketplace($url);
        if (isset($patterns[$marketplace]) && preg_match($patterns[$marketplace], $url, $m)) {
            return $marketplace.':'.strtoupper(implode('-', array_slice($m, 1)));
        }

        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return null;
        }

        return mb_substr(strtolower($parts['host']).rtrim($parts['path'] ?? '', '/'), 0, 191);
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
