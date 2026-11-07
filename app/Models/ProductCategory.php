<?php

namespace App\Models;

use App\Models\Concerns\BustsCatalogCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductCategory extends Model
{
    use BustsCatalogCache;

    /** Catálogo público que mostra este registro (cache limpo ao gravar). */
    public function catalogCompanyId(): ?int
    {
        return $this->company_id;
    }

    /**
     * Slug único por grupo (empresa + pai) — "Carros" pode existir em Chaveiros e em Miniaturas. Categoria e produto
     * dividem o mesmo espaço de nomes na URL do catálogo, então nenhum pode repetir o slug do outro na empresa.
     */
    public static function uniqueSlug(string $name, int $companyId, ?int $parentId): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;

        for ($suffix = 2; self::slugTaken($slug, $companyId, $parentId); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    private static function slugTaken(string $slug, int $companyId, ?int $parentId): bool
    {
        $categoryTaken = self::visibleTo($companyId)
            ->where('slug', $slug)
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'))
            ->when($parentId !== null, fn ($q) => $q->where('parent_id', $parentId))
            ->exists();

        return $categoryTaken || Product::where('company_id', $companyId)->where('slug', $slug)->exists();
    }

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

    /**
     * Pra listar: as padrão do nicho da empresa (e as que servem pra todos), as criadas por ela e
     * as padrão de outro nicho que ela já usa (ex.: trocou de nicho com cadastro feito).
     */
    public function scopeListedFor(Builder $query, Company $company): Builder
    {
        return $query->visibleTo($company->id)->where(fn ($q) => $q
            ->whereNotNull('company_id')
            ->orWhereNull('segment')
            ->orWhere('segment', $company->segment ?: Company::SEGMENT_3D)
            ->orWhereHas('products', fn ($used) => $used->where('company_id', $company->id))
            ->orWhereHas('children', fn ($child) => $child->where('company_id', $company->id)));
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
