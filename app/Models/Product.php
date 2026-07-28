<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    protected $fillable = [
        'company_id', 'name', 'sku', 'description', 'category_id', 'image_path', 'model_3d_url',
        'cost', 'sale_price', 'stock_quantity', 'made_to_order', 'featured', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'made_to_order' => 'boolean',
        'featured' => 'boolean',
        'cost' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock_quantity' => 'integer',
    ];

    protected $appends = ['image_url'];

    protected $with = ['images', 'category'];

    protected function imageUrl(): Attribute
    {
        return Attribute::get(function () {
            $cover = $this->images->first();
            if ($cover) {
                return $cover->image_url;
            }

            return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function quoteItems(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }
}
