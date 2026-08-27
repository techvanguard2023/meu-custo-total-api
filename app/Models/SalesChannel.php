<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesChannel extends Model
{
    protected $fillable = [
        'company_id', 'name', 'commission_percent', 'fixed_fee', 'active',
    ];

    protected $casts = [
        'commission_percent' => 'float',
        'fixed_fee' => 'float',
        'active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
