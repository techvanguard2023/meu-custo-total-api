<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class ProductCollection extends Model
{
    protected $fillable = ['company_id', 'name', 'slug', 'description', 'banner_path', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];

    protected $appends = ['banner_url'];

    protected function bannerUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->banner_path ? Storage::disk('public')->url($this->banner_path) : null
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Ordenados pela ordem de inclusão — sem coluna de posição dedicada (ver migration). */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_collection_product')->orderBy('product_collection_product.id');
    }
}
