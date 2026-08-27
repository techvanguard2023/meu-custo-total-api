<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Display extends Model
{
    public const STATUS_TESTING = 'testing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ENDED = 'ended';

    public const STATUSES = [
        self::STATUS_TESTING,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_ENDED,
    ];

    protected $fillable = [
        'company_id', 'name', 'contact_name', 'phone', 'address',
        'commission_percent', 'status', 'started_at', 'ended_at', 'notes',
    ];

    protected $casts = [
        'commission_percent' => 'float',
        'started_at' => 'date',
        'ended_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function stockLines(): HasMany
    {
        return $this->hasMany(DisplayStockLine::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(DisplayStockMovement::class);
    }

    /** Vendas apuradas nas conferências deste expositor. */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
