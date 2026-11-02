<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\OfferDelivery;
use App\Services\OfferPublisher;
use Illuminate\Console\Command;

/** Fila das ofertas: uma entrega por loja a cada minuto (espaçado, pra não parecer disparo em massa). */
class SendOffers extends Command
{
    protected $signature = 'offers:send';

    protected $description = 'Envia a próxima oferta pendente de cada loja nos grupos/canais de ofertas';

    public function handle(OfferPublisher $publisher): int
    {
        $companyIds = OfferDelivery::where('status', OfferDelivery::STATUS_PENDING)->distinct()->pluck('company_id');

        foreach (Company::active()->whereIn('id', $companyIds)->get() as $company) {
            $delivery = $publisher->sendNext($company);
            if ($delivery) {
                $this->line("Empresa #{$company->id}: {$delivery->target_label} → {$delivery->status}");
            }
        }

        return self::SUCCESS;
    }
}
