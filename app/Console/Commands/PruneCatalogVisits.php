<?php

namespace App\Console\Commands;

use App\Models\CatalogVisit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalog:prune-visits {--days=180 : Manter as visitas dos últimos N dias}')]
#[Description('Remove visitas antigas do catálogo — o dashboard só consulta os últimos 60 dias')]
class PruneCatalogVisits extends Command
{
    public function handle(): int
    {
        // Nunca abaixo de 60: o dashboard compara os últimos 30 dias com os 30 anteriores.
        $days = max(60, (int) $this->option('days'));
        $cutoff = now()->subDays($days)->toDateString();

        // Apaga em lotes pra não segurar a tabela num delete gigante de uma vez só.
        $total = 0;
        do {
            $deleted = CatalogVisit::where('visit_date', '<', $cutoff)->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Visitas removidas (anteriores a {$cutoff}): {$total}");

        return self::SUCCESS;
    }
}
