<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cupom automático da loja (ver App\Services\CouponAutomations). */
class CouponAutomation extends Model
{
    public const TYPE_REACTIVATION = 'reactivation';

    /** Padrão: cliente sem comprar há 90 dias ganha 10% por 15 dias; no máximo 20 por dia. */
    public const DEFAULTS = [
        'enabled' => false,
        'inactive_days' => 90,
        'discount_type' => Coupon::TYPE_PERCENT,
        'value' => 10,
        'min_order' => null,
        'validity_days' => 15,
        'daily_limit' => 20,
        'allow_promotions' => false,
    ];

    protected $fillable = ['company_id', 'type', 'enabled', 'inactive_days', 'discount_type', 'value', 'min_order', 'validity_days', 'daily_limit', 'allow_promotions'];

    protected $casts = [
        'enabled' => 'boolean',
        'inactive_days' => 'integer',
        'value' => 'decimal:2',
        'min_order' => 'decimal:2',
        'validity_days' => 'integer',
        'daily_limit' => 'integer',
        'allow_promotions' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
