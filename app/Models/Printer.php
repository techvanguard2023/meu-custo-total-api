<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Printer extends Model
{
    protected $fillable = [
        'company_id', 'name', 'model', 'power_watts',
        'purchase_price', 'lifespan_hours', 'maintenance_percent', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'purchase_price' => 'decimal:2',
        'maintenance_percent' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
