<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CouponAutomation;
use App\Services\CouponAutomations;
use Illuminate\Console\Command;
use Throwable;

/** Uma vez por dia: gera os cupons de reativação de quem sumiu (coupons:send-automated envia aos poucos). */
class GenerateReactivationCoupons extends Command
{
    protected $signature = 'coupons:reactivation';

    protected $description = 'Gera os cupons pessoais de reativação do dia (clientes que não compram há X dias)';

    public function handle(CouponAutomations $automations): int
    {
        $companyIds = CouponAutomation::where('type', CouponAutomation::TYPE_REACTIVATION)->where('enabled', true)->pluck('company_id');

        foreach (Company::active()->where('plan', Company::PLAN_PRO)->whereIn('id', $companyIds)->get() as $company) {
            try {
                $created = $automations->generate($company);
                if ($created > 0) {
                    $this->line("Empresa #{$company->id}: {$created} cupom(ns) de reativação");
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
