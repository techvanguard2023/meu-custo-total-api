<?php

namespace App\Console\Commands;

use App\Models\OfferSeries;
use App\Services\OfferSeriesManager;
use Illuminate\Console\Command;
use Throwable;

/** Confere quantas pessoas há nos grupos de ofertas e abre o próximo (#2, #3…) quando o atual lota. */
class CheckOfferGroups extends Command
{
    protected $signature = 'offers:check-groups';

    protected $description = 'Atualiza a contagem dos grupos de ofertas e abre um novo quando o atual lota';

    public function handle(OfferSeriesManager $manager): int
    {
        $series = OfferSeries::where('kind', OfferSeries::KIND_GROUP)->where('active', true)
            ->whereHas('company', fn ($q) => $q->active()->where('plan', 'pro')->whereNotNull('whatsapp_session_name'))
            ->with(['company', 'groups'])
            ->get();

        foreach ($series as $item) {
            try {
                $manager->checkGroups($item);
            } catch (Throwable $e) {
                report($e);
                $this->error("Série #{$item->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
