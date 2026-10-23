<?php

namespace App\Console\Commands;

use App\Services\Cashback;
use Illuminate\Console\Command;

/** Zera os créditos de cashback vencidos e registra no extrato do cliente. */
class ExpireCashback extends Command
{
    protected $signature = 'cashback:expire';

    protected $description = 'Vence os créditos de cashback que passaram da validade';

    public function handle(Cashback $cashback): int
    {
        $count = $cashback->expireDue();
        $this->info("{$count} crédito(s) vencido(s).");

        return self::SUCCESS;
    }
}
