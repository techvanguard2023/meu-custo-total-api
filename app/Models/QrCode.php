<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** QR Code de divulgação da loja (post, banner, panfleto...). Ver App\Services\QrCodes. */
class QrCode extends Model
{
    public const CHANNELS = ['instagram', 'facebook', 'flyer', 'banner', 'business_card', 'packaging', 'other'];

    public const DEST_CATALOG = 'catalog';
    public const DEST_PRODUCT = 'product';
    public const DEST_CATEGORY = 'category';
    public const DEST_COLLECTION = 'collection';
    public const DEST_WHATSAPP = 'whatsapp';
    public const DEST_INSTAGRAM = 'instagram';
    public const DEST_URL = 'url';

    /** Pix da loja: o QR leva o "Pix copia e cola" (fixo, sem link curto nem contagem). Ver App\Services\PixBrCode. */
    public const DEST_PIX = 'pix';

    public const DESTINATIONS = [
        self::DEST_CATALOG, self::DEST_PRODUCT, self::DEST_CATEGORY, self::DEST_COLLECTION,
        self::DEST_WHATSAPP, self::DEST_INSTAGRAM, self::DEST_URL, self::DEST_PIX,
    ];

    /** Essencial: até 5 QR Codes; Pro: sem limite. */
    public const ESSENTIAL_LIMIT = 5;

    public const KIND_SCAN = 'scan';
    public const KIND_LINK = 'link';

    /** Carrinho enviado pelo WhatsApp a partir do catálogo aberto pelo QR (não é acesso: é intenção de compra). */
    public const KIND_CART = 'cart';

    protected $fillable = [
        'company_id', 'name', 'code', 'channel', 'destination_type', 'destination_id', 'destination_url', 'whatsapp_message', 'active',
        'coupon_id', 'color', 'with_logo', 'headline', 'pix',
    ];

    protected $casts = [
        'pix' => 'array',
        'active' => 'boolean',
        'destination_id' => 'integer',
        'coupon_id' => 'integer',
        'with_logo' => 'boolean',
    ];

    public function isPix(): bool
    {
        return $this->destination_type === self::DEST_PIX;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(QrCodeEvent::class);
    }
}
