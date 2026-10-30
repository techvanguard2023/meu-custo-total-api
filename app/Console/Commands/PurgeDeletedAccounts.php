<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AccountDeletion;
use Illuminate\Console\Command;
use Throwable;

/** Apaga de vez as contas cujo prazo pra desistir da exclusão já passou. */
class PurgeDeletedAccounts extends Command
{
    protected $signature = 'account:purge-deleted';

    protected $description = 'Apaga as empresas com exclusão agendada cujo prazo já venceu';

    public function handle(AccountDeletion $deletion): int
    {
        $companies = Company::whereNotNull('deletion_scheduled_for')
            ->where('deletion_scheduled_for', '<=', now())
            ->get();

        foreach ($companies as $company) {
            try {
                $deletion->purge($company);
                $this->info("Empresa #{$company->id} ({$company->name}) excluída.");
            } catch (Throwable $e) {
                // Fica pra próxima execução (ex.: Stripe fora do ar) — sem cancelar a assinatura, não apaga
                report($e);
                $this->error("Empresa #{$company->id}: não excluída agora ({$e->getMessage()}).");
            }
        }

        return self::SUCCESS;
    }
}
