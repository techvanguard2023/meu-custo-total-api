<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variação de um produto (cor, tamanho e/ou peso). Quando um produto tem
 * variações, é aqui que o estoque de fato vive — o products.stock_quantity
 * deixa de ser usado nas vendas desse produto.
 *
 * Preço e custo são opcionais: em branco, herdam do produto. Isso evita
 * repetir o mesmo valor em toda variação quando só algumas fogem do padrão.
 */
class ProductVariation extends Model
{
    protected $fillable = [
        'product_id', 'color', 'size', 'weight', 'sku',
        'sale_price', 'cost', 'stock_quantity', 'active', 'position',
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
        'cost' => 'decimal:2',
        'stock_quantity' => 'integer',
        'active' => 'boolean',
        'position' => 'integer',
    ];

    protected $appends = ['display_name'];

    /** Nome montado a partir dos atributos preenchidos — ex: "Azul · P · 50g". */
    protected function displayName(): Attribute
    {
        return Attribute::get(function () {
            $parts = array_filter([$this->color, $this->size, $this->weight]);

            return $parts ? implode(' · ', $parts) : 'Padrão';
        });
    }

    /** Preço efetivo: o da variação quando definido, senão o do produto. */
    public function effectivePrice(?Product $product = null): ?float
    {
        if ($this->sale_price !== null) {
            return (float) $this->sale_price;
        }

        $product ??= $this->product;

        return $product?->sale_price !== null ? (float) $product->sale_price : null;
    }

    /** Custo efetivo: o da variação quando definido, senão o do produto. */
    public function effectiveCost(?Product $product = null): float
    {
        if ($this->cost !== null) {
            return (float) $this->cost;
        }

        $product ??= $this->product;

        return (float) ($product?->cost ?? 0);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
