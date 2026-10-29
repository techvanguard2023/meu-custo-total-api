<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialCategory extends Model
{
    public const PRICING_WEIGHT = 'weight';
    public const PRICING_UNIT = 'unit';

    protected $fillable = ['company_id', 'segment', 'name', 'pricing_unit'];

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
            ->orWhereHas('materials', fn ($used) => $used->where('company_id', $company->id)));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }
}
