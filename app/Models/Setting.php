<?php

namespace App\Models;

use App\Models\Concerns\BustsCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use BustsCatalogCache;

    /** Catálogo público que mostra este registro (cache limpo ao gravar). */
    public function catalogCompanyId(): ?int
    {
        return $this->company_id;
    }

    protected $fillable = [
        'company_id', 'electricity_rate_kwh', 'labor_hour_rate',
        'default_failure_rate', 'default_markup', 'minimum_order_price', 'order_discount_tiers',
        'cashback_enabled', 'cashback_purchase_percent', 'cashback_review_percent', 'cashback_referral_percent', 'cashback_max_redeem_percent', 'cashback_validity_months',
        'delivery_rules', 'payment_rules',
    ];

    protected $casts = [
        'electricity_rate_kwh' => 'decimal:4',
        'labor_hour_rate' => 'decimal:2',
        'default_failure_rate' => 'decimal:2',
        'default_markup' => 'decimal:2',
        'cashback_enabled' => 'boolean',
        'delivery_rules' => 'array',
        'payment_rules' => 'array',
        'cashback_purchase_percent' => 'decimal:2',
        'cashback_review_percent' => 'decimal:2',
        'cashback_referral_percent' => 'decimal:2',
        'cashback_max_redeem_percent' => 'decimal:2',
        'cashback_validity_months' => 'integer',
        'minimum_order_price' => 'decimal:2',
        'order_discount_tiers' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
