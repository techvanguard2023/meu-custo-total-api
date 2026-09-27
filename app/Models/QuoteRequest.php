<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'company_id', 'customer_id', 'quote_id',
        'item_description', 'colors', 'quantity', 'photo_urls', 'notes',
        'customer_name', 'customer_phone', 'status', 'external_reference',
    ];

    protected $casts = [
        'photo_urls' => 'array',
        'quantity' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
