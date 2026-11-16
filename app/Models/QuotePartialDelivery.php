<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma entrega de parte do pedido (os itens que já estavam prontos). Ver PartialDeliveryController. */
class QuotePartialDelivery extends Model
{
    protected $fillable = ['company_id', 'quote_id', 'user_id', 'items', 'note', 'customer_notified'];

    protected $casts = [
        'items' => 'array',
        'customer_notified' => 'boolean',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
