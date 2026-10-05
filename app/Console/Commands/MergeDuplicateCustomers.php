<?php

namespace App\Console\Commands;

use App\Services\CustomerMerger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customers:merge-duplicates {--apply : Executa de verdade (sem isso só mostra o que seria feito)} {--company= : ID da empresa}')]
#[Description('Une clientes duplicados (mesmo telefone em formatos diferentes), movendo pedidos e solicitações pro cadastro mais antigo')]
class MergeDuplicateCustomers extends Command
{
    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $groups = app(CustomerMerger::class)->duplicateGroups($this->option('company') ? (int) $this->option('company') : null);

        if ($groups->isEmpty()) {
            $this->info('Nenhum cliente duplicado.');

            return self::SUCCESS;
        }

        $merged = 0;

        foreach ($groups as $group) {
            $keep = $group->first();
            $extras = $group->slice(1);

            $this->line("Mantém #{$keep->id} {$keep->name} ({$keep->phone}) ← une ".$extras->map(fn ($c) => "#{$c->id} {$c->name} ({$c->phone})")->implode(', '));

            if (! $apply) {
                continue;
            }

            $merged += app(CustomerMerger::class)->merge($keep, $extras);
        }

        $this->info($apply ? "Concluído: {$merged} cadastro(s) duplicado(s) unido(s)." : 'Simulação — rode com --apply pra executar.');

        return self::SUCCESS;
    }
}
