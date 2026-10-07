<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ReportController extends Controller
{
    use EnforcesPlanLimits;

    private const MONTHS_PT = [
        1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
        'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
    ];

    /** Relatório financeiro detalhado do período (recurso Pro). */
    public function show(Request $request)
    {
        $this->requirePro($request, 'Relatórios financeiros');

        $data = $request->validate([
            'period' => ['required', 'in:day,month,year,range'],
            'date' => ['required_unless:period,range', 'nullable', 'string', 'max:10'],
            // Período livre: data inicial e final (inclusive), no máximo 5 anos.
            'from' => ['required_if:period,range', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,range', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        [$from, $to, $label] = $data['period'] === 'range'
            ? $this->resolveCustomRange($data['from'], $data['to'])
            : $this->resolveRange($data['period'], $data['date']);
        [$prevFrom, $prevTo] = $this->previousRange($data['period'], $from, $to);

        $sales = $this->salesBetween($request, $from, $to);
        $previousSales = $this->salesBetween($request, $prevFrom, $prevTo);

        $summary = $this->summarize($sales);
        $previousSummary = $this->summarize($previousSales);

        return response()->json([
            'period' => $data['period'],
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'label' => $label,
            ],
            'summary' => array_merge($summary, [
                'previous' => [
                    'revenue' => $previousSummary['revenue'],
                    'profit' => $previousSummary['profit'],
                    'sales_count' => $previousSummary['sales_count'],
                ],
                'revenue_change_percent' => $this->changePercent($previousSummary['revenue'], $summary['revenue']),
                'profit_change_percent' => $this->changePercent($previousSummary['profit'], $summary['profit']),
            ]),
            'costs' => $this->costBreakdown($sales),
            'timeline' => $this->timeline($sales, $data['period'], $from, $to),
            'top_products' => $this->topProducts($sales),
            'top_customers' => $this->topCustomers($sales),
            'sales' => $sales->map(fn ($sale) => [
                'id' => $sale->id,
                'name' => $this->cleanName($sale->name),
                'customer' => $sale->customer?->name,
                'approved_at' => $this->saleDate($sale)->toIso8601String(),
                'revenue' => round((float) $sale->final_price, 2),
                'cost' => $this->saleCost($sale),
                'profit' => round((float) $sale->profit_amount, 2),
                'production_status' => $sale->production_status,
            ])->values(),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    private function resolveRange(string $period, string $date): array
    {
        try {
            return match ($period) {
                'day' => (function () use ($date) {
                    $day = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();

                    return [$day, $day->copy()->endOfDay(), $day->format('d/m/Y')];
                })(),
                'month' => (function () use ($date) {
                    $month = Carbon::createFromFormat('Y-m', $date)->startOfMonth();
                    $label = self::MONTHS_PT[$month->month].' de '.$month->year;

                    return [$month, $month->copy()->endOfMonth(), $label];
                })(),
                'year' => (function () use ($date) {
                    $year = Carbon::createFromFormat('Y', $date)->startOfYear();

                    return [$year, $year->copy()->endOfYear(), 'Ano de '.$year->year];
                })(),
            };
        } catch (\Throwable) {
            abort(422, 'Data inválida para o período selecionado.');
        }
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    private function resolveCustomRange(string $fromDate, string $toDate): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $fromDate)->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $toDate)->endOfDay();

        abort_if($from->diffInDays($to) > 366 * 5, 422, 'O período pode ter no máximo 5 anos.');

        $label = $from->isSameDay($to)
            ? $from->format('d/m/Y')
            : $from->format('d/m/Y').' a '.$to->format('d/m/Y');

        return [$from, $to, $label];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function previousRange(string $period, Carbon $from, Carbon $to): array
    {
        return match ($period) {
            // Período livre: o intervalo de mesma duração imediatamente antes do escolhido.
            'range' => (function () use ($from, $to) {
                $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
                $prevTo = $from->copy()->subDay()->endOfDay();

                return [$prevTo->copy()->subDays($days - 1)->startOfDay(), $prevTo];
            })(),
            'day' => [$from->copy()->subDay()->startOfDay(), $from->copy()->subDay()->endOfDay()],
            'month' => [$from->copy()->subMonth()->startOfMonth(), $from->copy()->subMonth()->endOfMonth()],
            'year' => [$from->copy()->subYear()->startOfYear(), $from->copy()->subYear()->endOfYear()],
        };
    }

    private function salesBetween(Request $request, Carbon $from, Carbon $to): Collection
    {
        return $request->user()->company->quotes()
            ->where('status', 'approved')
            ->with(['customer', 'items.product'])
            ->get()
            ->filter(fn ($sale) => $this->saleDate($sale)->between($from, $to))
            ->sortBy(fn ($sale) => $this->saleDate($sale))
            ->values();
    }

    private function saleDate($sale): Carbon
    {
        return $sale->approved_at ?? $sale->created_at;
    }

    private function saleCost($sale): float
    {
        $productsCost = $sale->items
            ->where('type', 'product')
            ->sum(fn ($item) => (float) $item->unit_cost * (int) $item->quantity);

        return round((float) $sale->subtotal_cost + (float) $sale->failure_cost + $productsCost, 2);
    }

    private function summarize(Collection $sales): array
    {
        $revenue = round($sales->sum(fn ($s) => (float) $s->final_price), 2);
        $profit = round($sales->sum(fn ($s) => (float) $s->profit_amount), 2);
        $cost = round($sales->sum(fn ($s) => $this->saleCost($s)), 2);
        $count = $sales->count();

        return [
            'revenue' => $revenue,
            'total_cost' => $cost,
            'profit' => $profit,
            'sales_count' => $count,
            'avg_ticket' => $count > 0 ? round($revenue / $count, 2) : 0,
            'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0,
        ];
    }

    private function changePercent(float $previous, float $current): ?float
    {
        if (abs($previous) < 0.01) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    private function costBreakdown(Collection $sales): array
    {
        return [
            'material' => round($sales->sum(fn ($s) => (float) $s->material_cost), 2),
            'energy' => round($sales->sum(fn ($s) => (float) $s->energy_cost), 2),
            'depreciation' => round($sales->sum(fn ($s) => (float) $s->depreciation_cost), 2),
            'labor' => round($sales->sum(fn ($s) => (float) $s->labor_cost), 2),
            'failure' => round($sales->sum(fn ($s) => (float) $s->failure_cost), 2),
            'extra' => round($sales->sum(fn ($s) => (float) $s->extra_costs), 2),
            'products' => round($sales->sum(
                fn ($s) => $s->items->where('type', 'product')
                    ->sum(fn ($item) => (float) $item->unit_cost * (int) $item->quantity)
            ), 2),
        ];
    }

    private function timeline(Collection $sales, string $period, Carbon $from, Carbon $to): array
    {
        if ($period === 'day') {
            // Um único dia: cada venda é um ponto (a tabela detalha; sem série)
            return [];
        }

        $buckets = [];

        // Período livre: dia a dia até ~3 meses; acima disso agrupa por mês (senão o gráfico vira um borrão).
        $daily = $period === 'month' || ($period === 'range' && $from->diffInDays($to) <= 92);

        if ($daily) {
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $buckets[$day->format('Y-m-d')] = ['label' => $day->format('d/m'), 'revenue' => 0.0, 'profit' => 0.0, 'count' => 0];
            }
            $keyFor = fn (Carbon $date) => $date->format('Y-m-d');
        } elseif ($period === 'range') {
            for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonthNoOverflow()) {
                $buckets[$month->format('Y-m')] = [
                    'label' => mb_substr(self::MONTHS_PT[$month->month], 0, 3).'/'.$month->format('y'),
                    'revenue' => 0.0,
                    'profit' => 0.0,
                    'count' => 0,
                ];
            }
            $keyFor = fn (Carbon $date) => $date->format('Y-m');
        } else {
            for ($month = 1; $month <= 12; $month++) {
                $buckets[sprintf('%d-%02d', $from->year, $month)] = [
                    'label' => mb_substr(self::MONTHS_PT[$month], 0, 3),
                    'revenue' => 0.0,
                    'profit' => 0.0,
                    'count' => 0,
                ];
            }
            $keyFor = fn (Carbon $date) => $date->format('Y-m');
        }

        foreach ($sales as $sale) {
            $key = $keyFor($this->saleDate($sale));
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['revenue'] = round($buckets[$key]['revenue'] + (float) $sale->final_price, 2);
            $buckets[$key]['profit'] = round($buckets[$key]['profit'] + (float) $sale->profit_amount, 2);
            $buckets[$key]['count']++;
        }

        return array_values($buckets);
    }

    private function topProducts(Collection $sales): array
    {
        return $sales
            ->flatMap(fn ($s) => $s->items->where('type', 'product'))
            ->groupBy('description')
            ->map(fn ($items, $name) => [
                'name' => $name,
                'quantity' => (int) $items->sum('quantity'),
                'revenue' => round($items->sum(fn ($i) => (float) $i->amount), 2),
            ])
            ->sortByDesc('revenue')
            ->take(5)
            ->values()
            ->all();
    }

    private function topCustomers(Collection $sales): array
    {
        return $sales
            ->groupBy(fn ($s) => $s->customer?->name ?? 'Cliente avulso')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'sales_count' => $group->count(),
                'revenue' => round($group->sum(fn ($s) => (float) $s->final_price), 2),
            ])
            ->sortByDesc('revenue')
            ->take(5)
            ->values()
            ->all();
    }

    private function cleanName(string $name): string
    {
        return str_contains($name, '|__JSON__|') ? explode('|__JSON__|', $name)[0] : $name;
    }
}
