<?php

namespace App\Console\Commands;

use App\Services\ProTrialNotifier;
use Illuminate\Console\Command;

/** Mensagens do teste grátis do Pro (ver ProTrialNotifier). Roda a cada 15 minutos. */
class NotifyProTrials extends Command
{
    protected $signature = 'trial:notify';

    protected $description = 'Envia as mensagens do teste grátis do Pro (boas-vindas, dicas, fim e oferta)';

    public function handle(ProTrialNotifier $notifier): int
    {
        foreach ($notifier->run() as $companyId => $stage) {
            $this->line("Empresa #{$companyId}: {$stage}");
        }

        return self::SUCCESS;
    }
}
