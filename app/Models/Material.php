<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    protected $fillable = [
        'company_id', 'name', 'brand', 'type', 'material_category_id', 'color', 'pantone_code', 'hex_color',
        'spool_weight_g', 'spool_cost', 'cost_per_g', 'sale_price', 'density', 'stock_quantity',
        'purchase_url', 'image_url', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'spool_cost' => 'decimal:2',
        'cost_per_g' => 'decimal:4',
        'sale_price' => 'decimal:2',
        'sale_price_per_unit' => 'decimal:4',
        'density' => 'decimal:3',
        'stock_quantity' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Preço de venda por g/un sai do preço da embalagem, como o custo por g sai do custo do rolo
        static::saving(function (Material $material) {
            $weight = (float) $material->spool_weight_g;
            $material->sale_price_per_unit = $material->sale_price !== null && $weight > 0
                ? round((float) $material->sale_price / $weight, 4)
                : null;
        });
    }

    /** Vendido direto ao cliente: no orçamento entra pelo preço de venda (sem markup), não pelo custo. */
    public function isSoldDirectly(): bool
    {
        return $this->sale_price_per_unit !== null && (float) $this->sale_price_per_unit > 0;
    }

    /** Acabamento ou embalagem (pela categoria): fica fora da taxa de falha e aparece separado na ficha. */
    public function isFinishing(): bool
    {
        if ($this->material_category_id === null) {
            return false;
        }

        return $this->loadMissing('category')->category?->role === MaterialCategory::ROLE_FINISHING;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MaterialCategory::class, 'material_category_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(MaterialMovement::class);
    }

    /** Unidade em que o estoque e a ficha técnica são medidos — mesmo critério da tela de insumos. */
    public function unitLabel(): string
    {
        return $this->category?->pricing_unit === MaterialCategory::PRICING_WEIGHT ? 'g' : 'un';
    }
}
