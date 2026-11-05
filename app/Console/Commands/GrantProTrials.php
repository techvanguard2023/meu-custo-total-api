<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Campanha única: as lojas que estão no Grátis e nunca assinaram ganham o teste grátis do Pro (uma vez por loja).
 * Sem --apply só mostra quantas e quais receberiam.
 */
class GrantProTrials extends Command
{
    protected $signature = 'trial:grant-existing {--apply : começa o teste de verdade (sem isso, só simula)} {--days=14}';

    protected $description = 'Dá o teste grátis do Pro às lojas Grátis que nunca assinaram (uma vez)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $eligible = self::eligible()->orderBy('id')->get(['id', 'name', 'plan', 'created_at']);

        $this->table(['#', 'Loja', 'Cadastro'], $eligible->map(fn ($c) => [$c->id, $c->name, $c->created_at?->format('d/m/Y')])->all());
        if (! $this->option('apply')) {
            $this->info("{$eligible->count()} lojas receberiam {$days} dias de Pro (simulação — use --apply).");

            return self::SUCCESS;
        }

        $started = 0;
        foreach ($eligible as $company) {
            $started += (int) Company::find($company->id)?->startProTrial($days, 'campaign');
        }
        $this->info("Teste do Pro começou em {$started} lojas.");

        return self::SUCCESS;
    }

    /** No Grátis, nunca testou, nunca assinou (nem cancelada), sem cortesia, ativa e sem exclusão agendada. */
    public static function eligible(): Builder
    {
        return Company::query()
            ->where('plan', Company::PLAN_FREE)
            ->whereNull('pro_trial_started_at')
            ->whereNull('comp_plan')
            ->whereNull('blocked_at')
            ->whereNull('deletion_scheduled_for')
            ->whereDoesntHave('subscriptions')
            ->whereHas('users', fn ($u) => $u->where('role', 'owner'));
    }
}
