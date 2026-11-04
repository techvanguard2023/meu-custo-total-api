<?php

namespace App\Models\Concerns;

use App\Services\CatalogCache;

/**
 * Model que aparece no catálogo público: gravar, alterar estoque (increment/decrement) ou apagar limpa o cache
 * do catálogo da loja. Quem usa define catalogCompanyId(); sem loja (categoria padrão do sistema, usada por
 * todas), limpa o de todas.
 */
trait BustsCatalogCache
{
    public static function bootBustsCatalogCache(): void
    {
        $bust = function ($model) {
            $companyId = $model->catalogCompanyId();
            $companyId ? CatalogCache::forget($companyId) : CatalogCache::forgetAll();
        };
        static::saved($bust);
        // increment/decrement (baixa de estoque) disparam só "updated"
        static::updated($bust);
        static::deleted($bust);
    }

    abstract public function catalogCompanyId(): ?int;
}
