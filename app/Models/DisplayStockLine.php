<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quanto de um produto (ou variação) está fisicamente no expositor agora. */
class DisplayStockLine extends Model
{
    protected $fillable = [
        'display_id', 'product_id', 'product_variation_id', 'quantity_current',
    ];

    public function display(): BelongsTo
    {
        return $this->belongsTo(Display::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }
}
