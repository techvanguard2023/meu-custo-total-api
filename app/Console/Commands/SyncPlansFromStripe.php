<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plan:sync
    {company? : ID da empresa — se omitido, verifica todas}
    {--downgrade : Também rebaixa para "free" quem não tem assinatura Stripe válida (arriscado: pode afetar plano concedido manualmente, sem assinatura Stripe)}')]
#[Description('Corrige companies.plan pra bater com a assinatura Stripe local (essential/pro conforme o preço) — usado quando o webhook de plano falha ou se perde')]
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
            $correctPlan = Company::planForSubscription($company->subscription('default'));

            if ($wasPlan === $correctPlan) {
                $this->line("Empresa #{$company->id} ({$company->name}): já em \"{$wasPlan}\" (ok)");

                continue;
            }

            // Rebaixar pra "free" só quando não existe assinatura Stripe válida — a empresa
            // pode estar num plano pago concedido à mão, sem assinatura; exige --downgrade
            // explícito. Qualquer outra correção (inclusive Pro → Essencial) reflete uma
            // assinatura Stripe real e sempre pode ser aplicada.
            if ($correctPlan === Company::PLAN_FREE && ! $downgrade) {
                $this->line("Empresa #{$company->id} ({$company->name}): sem assinatura válida, mas está em \"{$wasPlan}\" — use --downgrade se for pra rebaixar.");

                continue;
            }

            $company->update(['plan' => $correctPlan]);
            $fixed++;
            $this->warn("Empresa #{$company->id} ({$company->name}): {$wasPlan} → {$correctPlan}");
        }

        $this->info("Concluído. {$fixed} empresa(s) corrigida(s) de {$companies->count()} verificada(s).");

        return self::SUCCESS;
    }
}
