<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrada ou saída de estoque de um insumo — explica por que o saldo está como está. */
class MaterialMovement extends Model
{
    public const TYPE_ADJUSTMENT = 'adjustment';          // edição manual do estoque no cadastro do insumo
    public const TYPE_PRODUCTION = 'production';          // "Registrar produção" de um produto
    public const TYPE_ORDER_PRODUCTION = 'order_production'; // pedido sob encomenda entrou em produção
    public const TYPE_ORDER_RETURN = 'order_return';      // pedido cancelado devolveu os insumos da produção
    public const TYPE_QUOTE = 'quote';                    // orçamento com o insumo foi aprovado
    public const TYPE_QUOTE_RETURN = 'quote_return';      // venda desse orçamento foi cancelada

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'material_id', 'type', 'quantity', 'balance_after',
        'product_id', 'product_variation_id', 'quote_id', 'user_id', 'note',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
