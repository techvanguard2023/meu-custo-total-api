<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const PRODUCTION_PENDING = 'pending';
    public const PRODUCTION_IN_PRODUCTION = 'in_production';
    public const PRODUCTION_FINISHED = 'finished';
    public const PRODUCTION_DELIVERED = 'delivered';

    public const PRODUCTION_STATUSES = [
        self::PRODUCTION_PENDING,
        self::PRODUCTION_IN_PRODUCTION,
        self::PRODUCTION_FINISHED,
        self::PRODUCTION_DELIVERED,
    ];

    protected $fillable = [
        'company_id', 'customer_id', 'printer_id', 'material_id',
        'name', 'quantity', 'print_time_minutes', 'material_weight_g',
        'setup_minutes', 'postprocess_minutes', 'extra_costs',
        'failure_rate_percent', 'markup_percent', 'discount_amount', 'delivery_days',
        'material_cost', 'energy_cost', 'depreciation_cost', 'labor_cost',
        'failure_cost', 'subtotal_cost', 'final_price', 'unit_price',
        'profit_amount', 'status', 'production_status', 'production_order', 'approved_at',
    ];

    protected $casts = [
        'material_weight_g' => 'decimal:2',
        'extra_costs' => 'decimal:2',
        'failure_rate_percent' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'material_cost' => 'decimal:2',
        'energy_cost' => 'decimal:2',
        'depreciation_cost' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'failure_cost' => 'decimal:2',
        'subtotal_cost' => 'decimal:2',
        'final_price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'profit_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }
}
