<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\OfferSchedule;
use App\Services\OfferScheduler;
use Illuminate\Console\Command;
use Throwable;

/** Agenda das ofertas: na hora marcada, os próximos produtos do rodízio entram na fila (offers:send envia). */
class RunOfferSchedules extends Command
{
    protected $signature = 'offers:schedule';

    protected $description = 'Coloca na fila as ofertas dos horários agendados que chegaram';

    public function handle(OfferScheduler $scheduler): int
    {
        $companyIds = OfferSchedule::where('enabled', true)->pluck('company_id');

        foreach (Company::active()->where('plan', Company::PLAN_PRO)->whereIn('id', $companyIds)->get() as $company) {
            try {
                $queued = $scheduler->run($company);
                if ($queued > 0) {
                    $this->line("Empresa #{$company->id}: {$queued} oferta(s) na fila");
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
