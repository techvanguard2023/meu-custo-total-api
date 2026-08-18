<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    protected $fillable = ['company_id', 'parent_id', 'name', 'slug'];

    protected $appends = ['is_default'];

    /** Categoria padrão do sistema — comum a todas as empresas e não editável. */
    public function getIsDefaultAttribute(): bool
    {
        return $this->company_id === null;
    }

    /** As padrão do sistema mais as criadas pela própria empresa. */
    public function scopeVisibleTo(Builder $query, int $companyId): Builder
    {
        return $query->where(
            fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId)
        );
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function products(): HasMany
    {
        // A coluna é `category_id`, não o `product_category_id` que o Eloquent inferiria.
        return $this->hasMany(Product::class, 'category_id');
    }
}
