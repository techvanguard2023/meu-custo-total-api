<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma opção da grade: soma price_delta ao preço e cost_delta ao custo de cada unidade vendida. */
class ProductOption extends Model
{
    protected $fillable = ['product_option_group_id', 'name', 'price_delta', 'cost_delta', 'active', 'position'];

    protected $casts = [
        'price_delta' => 'decimal:2',
        'cost_delta' => 'decimal:2',
        'active' => 'boolean',
        'position' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'product_option_group_id');
    }
}
