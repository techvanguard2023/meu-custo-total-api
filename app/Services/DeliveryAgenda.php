<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Quote;
use App\Support\Delivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Entregas da loja: vendas aprovadas com data de entrega. Usado pela Agenda (tela e Dashboard) e pela Alexa,
 * pra regra de "o que entra" e "o que está atrasado" ficar num lugar só. Ordem: data e hora (sem hora vai pro fim do dia).
 */
class DeliveryAgenda
{
    /** @return Collection<int, Quote> */
    public function between(Company $company, Carbon $from, Carbon $to): Collection
    {
        return $this->sorted($this->base($company)
            ->whereBetween('delivery_date', [$from->toDateString(), $to->toDateString()])
            ->get());
    }

    /** Data já passou e ainda não foi entregue. @return Collection<int, Quote> */
    public function overdue(Company $company): Collection
    {
        return $this->sorted($this->base($company)
            ->where('delivery_date', '<', now(Delivery::TZ)->toDateString())
            ->where('production_status', '!=', Quote::PRODUCTION_DELIVERED)
            ->get());
    }

    /** Próxima entrega ainda não feita, de hoje em diante. */
    public function next(Company $company): ?Quote
    {
        return $this->sorted($this->base($company)
            ->where('delivery_date', '>=', now(Delivery::TZ)->toDateString())
            ->where('production_status', '!=', Quote::PRODUCTION_DELIVERED)
            ->orderBy('delivery_date')
            ->limit(20)
            ->get())
            ->first(fn (Quote $q) => ! $this->alreadyPassedToday($q));
    }

    private function base(Company $company)
    {
        return $company->quotes()
            ->where('status', Quote::STATUS_APPROVED)
            ->whereNotNull('delivery_date')
            ->with(['customer:id,name,phone', 'items']);
    }

    /** @param Collection<int, Quote> $quotes */
    private function sorted(Collection $quotes): Collection
    {
        return $quotes->sortBy(fn (Quote $q) => $q->delivery_date->toDateString().' '.($q->delivery_time ?? '99:99'))->values();
    }

    /** Entrega de hoje com hora que já passou não é "a próxima". */
    private function alreadyPassedToday(Quote $q): bool
    {
        $now = now(Delivery::TZ);

        return $q->delivery_time
            && $q->delivery_date->toDateString() === $now->toDateString()
            && $q->delivery_time < $now->format('H:i');
    }
}
