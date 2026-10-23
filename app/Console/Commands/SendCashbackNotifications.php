<?php

namespace App\Console\Commands;

use App\Services\CashbackRetroactive;
use Illuminate\Console\Command;

/** Envia os avisos de cashback em fila — no máximo um por loja a cada execução (o agendador roda a cada minuto). */
class SendCashbackNotifications extends Command
{
    protected $signature = 'cashback:send-notifications';

    protected $description = 'Envia aos poucos os avisos de cashback que estão na fila';

    public function handle(CashbackRetroactive $retroactive): int
    {
        $sent = $retroactive->sendDue();
        if ($sent > 0) {
            $this->info("{$sent} aviso(s) enviado(s).");
        }

        return self::SUCCESS;
    }
}
