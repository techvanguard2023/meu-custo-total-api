<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $fillable = [
        'company_id', 'electricity_rate_kwh', 'labor_hour_rate',
        'default_failure_rate', 'default_markup', 'minimum_order_price',
    ];

    protected $casts = [
        'electricity_rate_kwh' => 'decimal:4',
        'labor_hour_rate' => 'decimal:2',
        'default_failure_rate' => 'decimal:2',
        'default_markup' => 'decimal:2',
        'minimum_order_price' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
