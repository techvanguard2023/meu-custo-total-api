<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Support\Delivery;
use Illuminate\Support\Carbon;

/**
 * Agenda (Fase 3): quais datas de entrega a loja aceita. Regras em settings.delivery_rules:
 *  - weekdays: dias em que trabalha (0 = domingo … 6 = sábado);
 *  - min_days: antecedência mínima em dias corridos (ex: bolo com 3 dias);
 *  - daily_limit: no máximo N pedidos por dia (null = sem limite) — conta as vendas aprovadas com a data, e o
 *    pedido do bot dividido em "pronto" + "sob encomenda" conta uma vez só;
 *  - blocked_dates: datas fechadas (férias, feriado);
 *  - methods: formas que a loja oferece ao cliente (retirada, entrega, envio);
 *  - customer_chooses: o catálogo e o bot oferecem as datas ao cliente.
 * Os produtos sob encomenda somam o prazo de produção deles (o maior), em dias úteis ou corridos.
 */
class DeliveryCalendar
{
    public const MAX_RANGE_DAYS = 90;

    public const DEFAULTS = [
        'weekdays' => [0, 1, 2, 3, 4, 5, 6],
        'min_days' => 0,
        'daily_limit' => null,
        'blocked_dates' => [],
        'methods' => [Delivery::METHOD_PICKUP, Delivery::METHOD_LOCAL, Delivery::METHOD_SHIPPING],
        'customer_chooses' => false,
    ];

    public static function rules(Company $company): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($company->setting?->delivery_rules ?? [], self::DEFAULTS));
    }

    /** O cliente pode escolher a data (catálogo/bot): Pro + a loja ligou a opção. */
    public static function customerChooses(Company $company): bool
    {
        return $company->isPro() && (bool) self::rules($company)['customer_chooses'];
    }

    /** Primeira data possível: hoje + antecedência da loja e + prazo de produção dos produtos sob encomenda. */
    public function earliest(Company $company, array $productIds = []): Carbon
    {
        $rules = self::rules($company);
        $date = Delivery::fromDays((int) $rules['min_days']);

        if ($productIds) {
            $lead = Product::where('company_id', $company->id)->whereIn('id', $productIds)
                ->where('made_to_order', true)->where('lead_time_days', '>', 0)
                ->get(['lead_time_days', 'lead_time_days_type'])
                ->map(fn (Product $p) => Delivery::fromDays((int) $p->lead_time_days, $p->lead_time_days_type === 'business'))
                ->max();
            if ($lead && $lead->gt($date)) {
                $date = $lead;
            }
        }

        return $date;
    }

    /**
     * Situação de cada dia do período: available, ou o motivo (too_soon, closed, blocked, full) — com quantos
     * pedidos já tem e o limite.
     *
     * @return array<int, array{date: string, available: bool, reason: ?string, count: int, limit: ?int}>
     */
    public function days(Company $company, Carbon $from, Carbon $to, array $productIds = [], ?int $ignoreQuoteId = null): array
    {
        $rules = self::rules($company);
        $earliest = $this->earliest($company, $productIds)->toDateString();
        $counts = $this->counts($company, $from, $to, $ignoreQuoteId);
        $limit = $rules['daily_limit'] ? (int) $rules['daily_limit'] : null;
        $blocked = array_flip($rules['blocked_dates']);
        $out = [];

        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $date = $d->toDateString();
            $count = $counts[$date] ?? 0;
            $reason = match (true) {
                $date < $earliest => 'too_soon',
                isset($blocked[$date]) => 'blocked',
                ! in_array($d->dayOfWeek, $rules['weekdays'], true) => 'closed',
                $limit !== null && $count >= $limit => 'full',
                default => null,
            };
            $out[] = ['date' => $date, 'available' => $reason === null, 'reason' => $reason, 'count' => $count, 'limit' => $limit];
        }

        return $out;
    }

    /** As próximas N datas livres (o que o catálogo e o bot oferecem). */
    public function nextAvailable(Company $company, array $productIds = [], int $howMany = 14): array
    {
        $from = now(Delivery::TZ)->startOfDay();
        $found = [];
        for ($i = 0; $i < 3 && count($found) < $howMany; $i++) {
            $start = $from->copy()->addDays($i * 60);
            foreach ($this->days($company, $start, $start->copy()->addDays(59), $productIds) as $day) {
                if ($day['available']) {
                    $found[] = $day['date'];
                    if (count($found) >= $howMany) {
                        break;
                    }
                }
            }
        }

        return $found;
    }

    /** null = a data pode; senão, a frase do motivo (com as próximas datas livres). */
    public function refusal(Company $company, string $date, array $productIds = []): ?string
    {
        $d = Carbon::parse($date, Delivery::TZ);
        $day = $this->days($company, $d, $d->copy(), $productIds)[0];
        if ($day['available']) {
            return null;
        }
        $why = match ($day['reason']) {
            'too_soon' => 'precisa de mais antecedência',
            'blocked' => 'a loja não atende nesse dia',
            'closed' => 'a loja não trabalha nesse dia da semana',
            'full' => 'a agenda desse dia já está cheia',
            default => 'não está disponível',
        };
        $next = collect($this->nextAvailable($company, $productIds, 5))->map(fn ($d) => Delivery::dateLabel($d))->implode('; ');

        return "A data ".Delivery::dateLabel($date)." não dá: {$why}.".($next ? " Próximas datas livres: {$next}." : '');
    }

    /** Pedidos por dia (vendas aprovadas com a data; pedido do bot dividido em duas partes conta uma vez). */
    private function counts(Company $company, Carbon $from, Carbon $to, ?int $ignoreQuoteId): array
    {
        return $company->quotes()
            ->where('status', Quote::STATUS_APPROVED)
            ->whereBetween('delivery_date', [$from->toDateString(), $to->toDateString()])
            ->when($ignoreQuoteId, fn ($q) => $q->whereKeyNot($ignoreQuoteId))
            ->selectRaw('delivery_date, COUNT(DISTINCT COALESCE(external_reference, CONCAT("q", id))) AS total')
            ->groupBy('delivery_date')
            ->pluck('total', 'delivery_date')
            ->mapWithKeys(fn ($total, $date) => [Carbon::parse($date)->toDateString() => (int) $total])
            ->all();
    }
}
