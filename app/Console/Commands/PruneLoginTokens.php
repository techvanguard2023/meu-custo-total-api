<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpa sessões velhas: login ("api") sem uso há 60 dias e tokens vencidos (ex: "Entrar como" da área admin).
 * Quem usa o app todo dia não é afetado; token de integração (bot) nunca é apagado aqui.
 */
class PruneLoginTokens extends Command
{
    protected $signature = 'tokens:prune {--days=60 : Dias sem uso pra apagar a sessão de login}';

    protected $description = 'Apaga sessões de login sem uso há muito tempo e tokens vencidos';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));

        $idle = DB::table('personal_access_tokens')->where('name', 'api')
            ->whereRaw('COALESCE(last_used_at, created_at) < ?', [$cutoff])
            ->delete();
        $expired = DB::table('personal_access_tokens')->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();

        $this->info("Sessões sem uso apagadas: {$idle} | tokens vencidos apagados: {$expired}");

        return self::SUCCESS;
    }
}
