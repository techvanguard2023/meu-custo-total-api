<?php

namespace App\Console\Commands;

use App\Services\PlatformFinance;
use Illuminate\Console\Command;

/** Financeiro da área admin: cobranças da Stripe, tarifas, custo da OpenAI e cotação do dólar. */
class SyncPlatformFinance extends Command
{
    protected $signature = 'admin:sync-finance {--full : Busca os últimos 13 meses (primeira vez)}';

    protected $description = 'Atualiza receita (cobranças da Stripe) e custos automáticos (taxas, OpenAI) da área admin';

    public function handle(PlatformFinance $finance): int
    {
        foreach ($finance->syncAll((bool) $this->option('full')) as $source => $result) {
            $this->line("{$source}: ".(is_bool($result) ? ($result ? 'ok' : 'não configurado') : $result));
        }

        return self::SUCCESS;
    }
}
