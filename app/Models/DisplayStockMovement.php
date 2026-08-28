<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma linha do extrato do expositor: reposição, venda apurada, perda ou devolução. */
class DisplayStockMovement extends Model
{
    public const TYPE_RESTOCK = 'restock';
    public const TYPE_SALE = 'sale';
    public const TYPE_LOSS = 'loss';
    public const TYPE_RETURN = 'return';

    protected $fillable = [
        'display_id', 'display_visit_id', 'product_id', 'product_variation_id', 'type', 'quantity', 'quote_id',
    ];

    public function display(): BelongsTo
    {
        return $this->belongsTo(Display::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(DisplayVisit::class, 'display_visit_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
