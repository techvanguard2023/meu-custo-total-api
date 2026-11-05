<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Números do teste grátis do Pro pra Visão geral do admin.
 *
 * Converteu = a loja criou a assinatura entre o começo do teste e 14 dias depois do fim (no teste, ou depois com
 * a oferta de 30%, que vale 7 dias). Conta pela primeira assinatura da loja, mesmo que depois tenha cancelado.
 * Quem terminou há menos de 14 dias e não assinou ainda está "decidindo".
 */
class ProTrialStats
{
    public const WINDOW_DAYS = 90;

    public const GRACE_DAYS = 14;

    public function summary(): array
    {
        $trials = $this->trials();
        $now = now();

        $active = $trials->filter(fn ($t) => $t->ends_at->isFuture());
        $ended = $trials->filter(fn ($t) => ! $t->ends_at->isFuture() && $t->ends_at->gte($now->copy()->subDays(self::WINDOW_DAYS)));
        $deciding = $ended->filter(fn ($t) => ! $t->converted && $t->ends_at->gt($now->copy()->subDays(self::GRACE_DAYS)));

        return [
            'window_days' => self::WINDOW_DAYS,
            'active' => $active->count(),
            'ending_3d' => $active->filter(fn ($t) => $t->ends_at->lte($now->copy()->addDays(3)))->count(),
            'active_by_source' => $this->bySource($active, fn ($group) => $group->count()),
            // Terminados nos últimos 90 dias
            'ended' => $ended->count(),
            'converted_during' => $ended->where('converted', 'during')->count() + $active->where('converted', 'during')->count(),
            'converted_after' => $ended->where('converted', 'after')->count(),
            'deciding' => $deciding->count(),
            // Taxa só entre quem já decidiu (assinou, ou terminou há mais de 14 dias)
            'conversion_rate' => $this->rate($ended->reject(fn ($t) => $deciding->contains('id', $t->id))),
            'conversion_by_source' => $this->bySource($ended->reject(fn ($t) => $deciding->contains('id', $t->id)), fn ($group) => $this->rate($group)),
            // Ativação: o que quem testou chegou a fazer, e quanto cada grupo converteu
            'activation' => $this->activation($ended->reject(fn ($t) => $deciding->contains('id', $t->id))),
            'cohorts' => $this->cohorts($trials),
        ];
    }

    /** Testes começados nos últimos 90 dias + 14 de folga, com a 1ª assinatura e o uso durante o teste. */
    private function trials(): Collection
    {
        $since = now()->subDays(self::WINDOW_DAYS + self::GRACE_DAYS + 31);

        return Company::query()
            ->whereNotNull('pro_trial_started_at')
            ->where('pro_trial_ends_at', '>=', $since)
            ->select(['id', 'pro_trial_started_at', 'pro_trial_ends_at', 'pro_trial_source', 'whatsapp_session_name'])
            ->addSelect([
                'first_subscription_at' => DB::table('subscriptions')->selectRaw('MIN(created_at)')->whereColumn('subscriptions.company_id', 'companies.id')->where('type', 'default'),
                'quotes_in_trial' => DB::table('quotes')->selectRaw('COUNT(*)')->whereColumn('quotes.company_id', 'companies.id')
                    ->whereColumn('quotes.created_at', '>=', 'companies.pro_trial_started_at')->whereColumn('quotes.created_at', '<=', 'companies.pro_trial_ends_at'),
                'products_count' => DB::table('products')->selectRaw('COUNT(*)')->whereColumn('products.company_id', 'companies.id'),
            ])
            ->get()
            ->map(function (Company $c) {
                $sub = $c->first_subscription_at ? \Illuminate\Support\Carbon::parse($c->first_subscription_at) : null;
                $converted = null;
                if ($sub && $sub->gte($c->pro_trial_started_at->copy()->subMinute())) {
                    if ($sub->lte($c->pro_trial_ends_at)) {
                        $converted = 'during';
                    } elseif ($sub->lte($c->pro_trial_ends_at->copy()->addDays(self::GRACE_DAYS))) {
                        $converted = 'after';
                    }
                }

                return (object) [
                    'id' => $c->id,
                    'started_at' => $c->pro_trial_started_at,
                    'ends_at' => $c->pro_trial_ends_at,
                    'source' => $c->pro_trial_source ?: 'signup',
                    'converted' => $converted,
                    'made_quote' => (int) $c->quotes_in_trial > 0,
                    'has_products' => (int) $c->products_count > 0,
                    'whatsapp' => $c->whatsapp_session_name !== null,
                ];
            });
    }

    /** @return array{total: int, converted: int, rate: float|null} */
    private function rate(Collection $group): array
    {
        $converted = $group->whereNotNull('converted')->count();

        return ['total' => $group->count(), 'converted' => $converted, 'rate' => $group->count() ? round($converted / $group->count() * 100, 1) : null];
    }

    private function bySource(Collection $trials, callable $fn): array
    {
        return [
            'signup' => $fn($trials->where('source', 'signup')),
            'campaign' => $fn($trials->where('source', 'campaign')),
        ];
    }

    private function activation(Collection $decided): array
    {
        return collect([
            'made_quote' => 'Fizeram orçamento no teste',
            'has_products' => 'Cadastraram produtos',
            'whatsapp' => 'Conectaram o WhatsApp',
        ])->map(fn ($label, $key) => [
            'key' => $key,
            'label' => $label,
            'share' => $decided->count() ? round($decided->where($key, true)->count() / $decided->count() * 100, 1) : null,
            'with' => $this->rate($decided->where($key, true)),
            'without' => $this->rate($decided->where($key, false)),
        ])->values()->all();
    }

    /** Por semana de início (8 semanas): começaram, assinaram (no teste ou até 14 dias depois), ainda em teste. */
    private function cohorts(Collection $trials): array
    {
        return collect(range(7, 0))->map(function ($ago) use ($trials) {
            $start = now()->startOfWeek()->subWeeks($ago);
            $week = $trials->filter(fn ($t) => $t->started_at->between($start, $start->copy()->endOfWeek()));

            return [
                'week' => $start->toDateString(),
                'started' => $week->count(),
                'converted' => $week->whereNotNull('converted')->count(),
                'in_trial' => $week->filter(fn ($t) => $t->ends_at->isFuture())->count(),
            ];
        })->values()->all();
    }
}
