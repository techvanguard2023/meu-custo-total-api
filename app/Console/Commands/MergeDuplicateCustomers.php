<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('customers:merge-duplicates {--apply : Executa de verdade (sem isso só mostra o que seria feito)} {--company= : ID da empresa}')]
#[Description('Une clientes duplicados (mesmo telefone em formatos diferentes), movendo pedidos e solicitações pro cadastro mais antigo')]
class MergeDuplicateCustomers extends Command
{
    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $query = Customer::query()->whereNotNull('phone')->orderBy('id');
        if ($companyId = $this->option('company')) {
            $query->where('company_id', $companyId);
        }

        $groups = $query->get()
            ->filter(fn ($c) => Customer::phoneKey($c->phone) !== null)
            ->groupBy(fn ($c) => $c->company_id.'|'.Customer::phoneKey($c->phone))
            ->filter(fn ($group) => $group->count() > 1);

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

            DB::transaction(function () use ($keep, $extras) {
                foreach ($extras as $extra) {
                    Quote::where('customer_id', $extra->id)->update(['customer_id' => $keep->id]);
                    QuoteRequest::where('customer_id', $extra->id)->update(['customer_id' => $keep->id]);

                    // Aproveita e-mail/observações que só o duplicado tinha.
                    $keep->email = $keep->email ?: $extra->email;
                    $keep->notes = trim(($keep->notes ?? '').($extra->notes ? "\n".$extra->notes : '')) ?: null;
                    $extra->delete();
                }
                $keep->save();
            });

            $merged += $extras->count();
        }

        $this->info($apply ? "Concluído: {$merged} cadastro(s) duplicado(s) unido(s)." : 'Simulação — rode com --apply pra executar.');

        return self::SUCCESS;
    }
}
