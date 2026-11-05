<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AdminDiscount;
use Illuminate\Console\Command;

/**
 * Fim do teste grátis do Pro: a loja volta pro plano da assinatura (ou Grátis). Nada é apagado — só os
 * recursos Pro param e os limites do Grátis voltam a valer pra criar coisas novas. Roda de hora em hora.
 */
class ExpireProTrials extends Command
{
    protected $signature = 'trial:expire';

    protected $description = 'Encerra os testes grátis do Pro que passaram da data';

    public function handle(): int
    {
        Company::whereNotNull('pro_trial_ends_at')
            ->where('pro_trial_ends_at', '<=', now())
            ->whereNull('pro_trial_ended_at')
            ->each(function (Company $company) {
                $from = $company->plan;
                $company->forceFill(['pro_trial_ended_at' => now()])->save();
                $company->update(['plan' => $company->planFromBilling()]);
                $this->line("Empresa #{$company->id}: teste do Pro encerrado ({$from} → {$company->plan})");
            });

        // Desconto pendente com prazo (oferta do fim do teste) que venceu sem a loja assinar: sai da Stripe e da loja
        Company::whereNotNull('admin_discount')->each(function (Company $company) {
            if (AdminDiscount::expired($company->admin_discount)) {
                try {
                    app(AdminDiscount::class)->remove($company);
                    $this->line("Empresa #{$company->id}: desconto pendente venceu e foi removido");
                } catch (\Throwable $e) {
                    $this->warn("Empresa #{$company->id}: desconto vencido não removido ({$e->getMessage()})");
                }
            }
        });

        return self::SUCCESS;
    }
}
