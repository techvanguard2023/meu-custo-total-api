<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

/** Cortesias vencidas (área admin): a loja volta pro plano da assinatura Stripe (ou Grátis, sem assinatura). */
class ExpireCompPlans extends Command
{
    protected $signature = 'admin:expire-comps';

    protected $description = 'Encerra os planos de cortesia que passaram da data e devolve a loja ao plano da assinatura';

    public function handle(): int
    {
        $expired = Company::whereNotNull('comp_plan')->whereNotNull('comp_until')->where('comp_until', '<', now()->toDateString())->get();

        foreach ($expired as $company) {
            $from = $company->plan;
            $company->forceFill(['comp_plan' => null, 'comp_until' => null, 'comp_note' => null])->save();
            $company->update(['plan' => $company->planFromBilling()]);
            $this->line("Empresa #{$company->id}: cortesia encerrada ({$from} → {$company->plan})");
        }

        return self::SUCCESS;
    }
}
