<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha da ficha técnica: quanto de um insumo vai em uma unidade do produto (gramas
 * pra filamento, unidades pra argola/embalagem — segue a categoria do insumo).
 * Sem variação vale pra todas; com variação, soma às linhas comuns.
 */
class ProductMaterial extends Model
{
    protected $fillable = ['product_id', 'product_variation_id', 'material_id', 'quantity'];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
