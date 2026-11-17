<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro da entrega dividida: itens que ficaram prontos (finished) ou que foram entregues (delivered). */
class QuotePartialDelivery extends Model
{
    public const KIND_FINISHED = 'finished';

    public const KIND_DELIVERED = 'delivered';

    protected $fillable = ['company_id', 'quote_id', 'user_id', 'kind', 'items', 'note', 'customer_notified'];

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
