<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    protected $fillable = [
        'company_id', 'name', 'brand', 'type', 'color',
        'spool_weight_g', 'spool_cost', 'cost_per_g', 'density', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'spool_cost' => 'decimal:2',
        'cost_per_g' => 'decimal:4',
        'density' => 'decimal:3',
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
