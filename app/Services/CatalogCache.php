<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cache do catálogo público (a página mais aberta pelos clientes das lojas). A resposta é a mesma pra todo
 * visitante; fica guardada por loja e é apagada sempre que algo que aparece nela muda (produto, variação,
 * foto, categoria, coleção, avaliação, banner, cupom, configuração, estoque) — ver BustsCatalogCache. Vale no
 * máximo 10 minutos, o que mantém "mais vendidos" e a validade dos cupons sempre recentes.
 */
class CatalogCache
{
    private const TTL_MINUTES = 10;

    public static function remember(int $companyId, Closure $build): array
    {
        return Cache::remember(self::key($companyId), now()->addMinutes(self::TTL_MINUTES), $build);
    }

    /**
     * Apaga na hora e de novo quando a transação em andamento terminar: senão uma visita no meio da gravação
     * guardaria a versão antiga por até 10 minutos.
     */
    /** Algo compartilhado por todas as lojas mudou (ex: categoria padrão do sistema): invalida todos os catálogos. */
    public static function forgetAll(): void
    {
        Cache::forever('catalog-generation', (string) microtime(true));
    }

    public static function forget(?int $companyId): void
    {
        if (! $companyId) {
            return;
        }

        Cache::forget(self::key($companyId));
        DB::afterCommit(fn () => Cache::forget(self::key($companyId)));
    }

    private static function key(int $companyId): string
    {
        return "catalog-payload:{$companyId}:".Cache::get('catalog-generation', '0');
    }
}
