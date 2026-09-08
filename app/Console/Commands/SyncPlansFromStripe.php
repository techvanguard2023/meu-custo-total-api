<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plan:sync
    {company? : ID da empresa — se omitido, verifica todas}
    {--downgrade : Também rebaixa para "free" quem não tem assinatura local válida (arriscado: pode afetar Pro concedido manualmente, sem assinatura Stripe)}')]
#[Description('Promove para "pro" quem tem assinatura Stripe ativa mas ficou preso em "free" — corrige quando o webhook de plano falha ou se perde')]
class SyncPlansFromStripe extends Command
{
    public function handle(): int
    {
        $query = Company::query();

        if ($companyId = $this->argument('company')) {
            $query->where('id', $companyId);
        } else {
            $query->whereNotNull('stripe_id');
        }

        $companies = $query->get();

        if ($companies->isEmpty()) {
            $this->info('Nenhuma empresa encontrada.');

            return self::SUCCESS;
        }

        $downgrade = (bool) $this->option('downgrade');
        $fixed = 0;

        foreach ($companies as $company) {
            $wasPlan = $company->plan;
            // Lê a assinatura já sincronizada localmente (subscriptions table) — não faz
            // chamada à Stripe. Por padrão só promove (free → pro): é o sentido seguro,
            // que desbloqueia quem já pagou. Rebaixar (pro → free) é arriscado — uma
            // empresa pode estar em "pro" sem assinatura Stripe (cortesia, concedido à
            // mão) — por isso exige --downgrade explícito.
            $hasValidSubscription = $company->subscribed('default');

            if ($wasPlan === Company::PLAN_FREE && $hasValidSubscription) {
                $company->update(['plan' => Company::PLAN_PRO]);
                $fixed++;
                $this->warn("Empresa #{$company->id} ({$company->name}): free → pro");
            } elseif ($downgrade && $wasPlan === Company::PLAN_PRO && ! $hasValidSubscription) {
                $company->update(['plan' => Company::PLAN_FREE]);
                $fixed++;
                $this->warn("Empresa #{$company->id} ({$company->name}): pro → free");
            } else {
                $this->line("Empresa #{$company->id} ({$company->name}): já em \"{$wasPlan}\" (ok)");
            }
        }

        $this->info("Concluído. {$fixed} empresa(s) corrigida(s) de {$companies->count()} verificada(s).");

        return self::SUCCESS;
    }
}
