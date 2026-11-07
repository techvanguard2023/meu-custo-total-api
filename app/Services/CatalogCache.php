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

    /** Lojas a limpar no fim do lote em andamento (null = fora de um lote). */
    private static ?array $batched = null;

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
        if (self::$batched !== null) {
            self::$batched[$companyId] = true;

            return;
        }

        Cache::forget(self::key($companyId));
        DB::afterCommit(fn () => Cache::forget(self::key($companyId)));
    }

    /**
     * Junta as limpezas de um lote grande (ex: importar 2000 produtos de planilha): uma por loja no fim, em vez de
     * uma por registro gravado.
     */
    public static function batch(Closure $work): mixed
    {
        if (self::$batched !== null) {
            return $work();
        }
        self::$batched = [];
        try {
            return $work();
        } finally {
            $companyIds = array_keys(self::$batched);
            self::$batched = null;
            foreach ($companyIds as $companyId) {
                self::forget($companyId);
            }
        }
    }

    private static function key(int $companyId): string
    {
        return "catalog-payload:{$companyId}:".Cache::get('catalog-generation', '0');
    }
}
