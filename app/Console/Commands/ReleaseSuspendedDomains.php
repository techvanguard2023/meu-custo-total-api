<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\VercelDomains;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Domínios próprios de lojas que saíram do Pro há mais de 30 dias: descadastra da Vercel e libera o endereço
 * (outra loja pode usar). Antes disso, ficam guardados e parados — voltando ao Pro, voltam a valer sozinhos.
 */
class ReleaseSuspendedDomains extends Command
{
    public const GRACE_DAYS = 30;

    protected $signature = 'domains:release-suspended';

    protected $description = 'Libera os domínios próprios das lojas que estão fora do plano Pro há mais de 30 dias';

    public function handle(VercelDomains $vercel): int
    {
        $companies = Company::whereNotNull('domains_suspended_at')
            ->where('domains_suspended_at', '<', now()->subDays(self::GRACE_DAYS))
            ->where('plan', '!=', Company::PLAN_PRO)
            ->get();

        foreach ($companies as $company) {
            $released = [];
            foreach (Company::DOMAIN_COLUMNS as $column) {
                if (! $company->{$column}) {
                    continue;
                }
                try {
                    if ($vercel->isConfigured()) {
                        $vercel->remove($company->{$column});
                    }
                } catch (\Throwable $e) {
                    Log::warning("Domínio próprio não removido da Vercel ({$company->{$column}}): ".$e->getMessage());
                }
                $released[] = $company->{$column};
                $company->forceFill([$column => null, "{$column}_verified_at" => null]);
            }
            $company->forceFill(['domains_suspended_at' => null])->saveQuietly();
            if ($released) {
                $this->line("Empresa #{$company->id}: domínios liberados (".implode(', ', $released).')');
            }
        }

        return self::SUCCESS;
    }
}
