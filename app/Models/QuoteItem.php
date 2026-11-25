<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    /** Embalagem do pedido (Configurações): só custo e baixa de estoque, nunca aparece como item vendido. */
    public const TYPE_PACKAGING = 'packaging';

    protected $fillable = [
        'quote_id', 'product_id', 'product_variation_id', 'material_id', 'description', 'type',
        'quantity', 'finished_quantity', 'delivered_quantity', 'unit_weight', 'unit_price', 'unit_cost', 'amount',
        'stock_deducted', 'materials_consumed_at', 'options',
    ];

    protected $casts = [
        // Opções escolhidas na venda: [{group, name, price_delta, cost_delta}] — foto do momento da venda
        'options' => 'array',
        'quantity' => 'integer',
        // Entrega dividida: quanto deste item já foi entregue ao cliente
        'delivered_quantity' => 'integer',
        // Quantas unidades já estão prontas (Finalizado) — as entregues contam como prontas
        'finished_quantity' => 'integer',
        'unit_weight' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'amount' => 'decimal:2',
        'stock_deducted' => 'boolean',
        'materials_consumed_at' => 'datetime',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }
}
