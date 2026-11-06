<?php

namespace App\Console\Commands;

use App\Services\Referrals;
use Illuminate\Console\Command;

/**
 * Indique e ganhe: loja indicada que fez o primeiro pagamento → a que indicou ganha 1 mês (crédito na mensalidade ou
 * cortesia Pro) e recebe o aviso. Roda de hora em hora; o pagamento chega pelo webhook da Stripe ou pelo
 * admin:sync-finance diário.
 */
class RewardReferrals extends Command
{
    protected $signature = 'referrals:reward';

    protected $description = 'Dá 1 mês de Pro a quem indicou uma loja que fez o primeiro pagamento';

    public function handle(Referrals $referrals): int
    {
        foreach ($referrals->rewardDue() as $companyId => $reward) {
            $this->line("Loja indicada #{$companyId}: recompensa {$reward['type']}");
        }

        return self::SUCCESS;
    }
}
