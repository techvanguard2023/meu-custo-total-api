<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grade de opções de um produto ("Acabamento": fosco, brilho...). Ver ProductOption e OptionPicker. */
class ProductOptionGroup extends Model
{
    public const SINGLE = 'single';

    public const MULTIPLE = 'multiple';

    public const TYPES = [self::SINGLE, self::MULTIPLE];

    protected $fillable = ['product_id', 'name', 'type', 'required', 'position'];

    protected $casts = [
        'required' => 'boolean',
        'position' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position')->orderBy('id');
    }
}
