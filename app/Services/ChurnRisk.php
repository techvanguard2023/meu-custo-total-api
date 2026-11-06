<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PlatformFeedback;
use App\Models\SupportTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Risco de cancelar — só para quem paga (assinatura ativa, em teste da Stripe ou com pagamento falhando). Soma
 * sinais simples e explica cada um:
 *  - pagamento falhando (+4);
 *  - sem acessar o painel há 21+ dias (+4) ou 10+ dias (+2);
 *  - nenhum orçamento em 30 dias (+2) ou orçamentos caindo pela metade (+2);
 *  - última nota do NPS de 0 a 6 (+3); atendimento avaliado 1–2 nos últimos 60 dias (+1); chamado aberto (+1).
 * Alto = 6+, médio = 3 a 5. Quem já cancelou (no período pago) aparece como "cancelando".
 * Tudo em poucas consultas agrupadas, pra servir à Visão geral e à ficha da loja.
 */
class ChurnRisk
{
    public const HIGH = 6;

    public const MEDIUM = 3;

    /** As lojas pagantes em risco (médio ou alto), da maior pontuação para a menor. */
    public function list(int $limit = 15): array
    {
        $rows = $this->evaluate();

        return [
            'high' => $rows->where('level', 'high')->count(),
            'medium' => $rows->where('level', 'medium')->count(),
            'canceling' => $rows->where('level', 'canceling')->count(),
            'paying' => $rows->count(),
            'companies' => $rows->whereIn('level', ['canceling', 'high', 'medium'])->sortByDesc('score')->take($limit)->values()->all(),
        ];
    }

    /** Uma loja (ficha): null se não é pagante. */
    public function forCompany(Company $company): ?array
    {
        return $this->evaluate([$company->id])->first();
    }

    private function evaluate(?array $onlyIds = null): Collection
    {
        $now = now();
        $subs = DB::table('subscriptions')->where('type', 'default')
            ->whereIn('stripe_status', ['active', 'trialing', 'past_due'])
            ->when($onlyIds, fn ($q) => $q->whereIn('company_id', $onlyIds))
            ->orderBy('created_at')
            ->get(['company_id', 'stripe_status', 'ends_at'])
            ->keyBy('company_id'); // a mais recente fica por último e vence

        $ids = Company::whereIn('id', $subs->keys())->whereNull('deletion_scheduled_for')->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }

        $companies = Company::whereIn('id', $ids)->get(['id', 'name', 'plan']);
        $lastSeen = DB::table('users')->whereIn('company_id', $ids)->groupBy('company_id')->selectRaw('company_id, MAX(last_seen_at) AS seen')->pluck('seen', 'company_id');
        $quotes = DB::table('quotes')->whereIn('company_id', $ids)->where('created_at', '>=', $now->copy()->subDays(60))
            ->groupBy('company_id')
            ->selectRaw('company_id, SUM(created_at >= ?) AS last30, SUM(created_at < ?) AS prev30', [$now->copy()->subDays(30), $now->copy()->subDays(30)])
            ->get()->keyBy('company_id');
        $nps = PlatformFeedback::whereIn('company_id', $ids)->where('kind', PlatformFeedback::KIND_NPS)->where('created_at', '>=', $now->copy()->subDays(180))
            ->orderBy('id')->get(['company_id', 'score'])->keyBy('company_id'); // a mais recente vence
        $badSupport = PlatformFeedback::whereIn('company_id', $ids)->where('kind', PlatformFeedback::KIND_SUPPORT_CSAT)->where('score', '<=', 2)
            ->where('created_at', '>=', $now->copy()->subDays(60))->distinct()->pluck('company_id')->flip();
        $openTickets = SupportTicket::whereIn('company_id', $ids)->where('status', SupportTicket::STATUS_OPEN)->groupBy('company_id')
            ->selectRaw('company_id, COUNT(*) AS total')->pluck('total', 'company_id');
        $trackingSince = DB::table('users')->min('last_seen_at');

        return $companies->map(function (Company $c) use ($subs, $lastSeen, $quotes, $nps, $badSupport, $openTickets, $trackingSince, $now) {
            $sub = $subs[$c->id];
            $score = 0;
            $reasons = [];

            if ($sub->ends_at && Carbon::parse($sub->ends_at)->isFuture()) {
                return [
                    'company_id' => $c->id, 'name' => $c->name, 'plan' => $c->plan, 'score' => 100, 'level' => 'canceling',
                    'reasons' => ['Cancelou — o acesso pago vai até '.Carbon::parse($sub->ends_at)->timezone('America/Sao_Paulo')->format('d/m')],
                ];
            }
            if ($sub->stripe_status === 'past_due') {
                $score += 4;
                $reasons[] = 'Pagamento falhando';
            }

            $seen = $lastSeen[$c->id] ?? null;
            $days = $seen ? (int) Carbon::parse($seen)->diffInDays($now) : null;
            if ($days === null) {
                // Sem registro: só conta se o registro de acesso já existe há tempo suficiente
                if ($trackingSince && Carbon::parse($trackingSince)->lt($now->copy()->subDays(21))) {
                    $score += 4;
                    $reasons[] = 'Não acessou o painel desde que o acesso passou a ser registrado';
                }
            } elseif ($days >= 21) {
                $score += 4;
                $reasons[] = "Sem acessar o painel há {$days} dias";
            } elseif ($days >= 10) {
                $score += 2;
                $reasons[] = "Sem acessar o painel há {$days} dias";
            }

            $q = $quotes[$c->id] ?? null;
            $last30 = (int) ($q->last30 ?? 0);
            $prev30 = (int) ($q->prev30 ?? 0);
            if ($last30 === 0) {
                $score += 2;
                $reasons[] = 'Nenhum orçamento nos últimos 30 dias';
            } elseif ($prev30 >= 4 && $last30 <= $prev30 / 2) {
                $score += 2;
                $reasons[] = "Orçamentos caíram de {$prev30} para {$last30} (30 dias)";
            }

            if (isset($nps[$c->id]) && $nps[$c->id]->score <= 6) {
                $score += 3;
                $reasons[] = "Deu nota {$nps[$c->id]->score} no NPS";
            }
            if ($badSupport->has($c->id)) {
                $score += 1;
                $reasons[] = 'Avaliou mal um atendimento';
            }
            if (($openTickets[$c->id] ?? 0) > 0) {
                $score += 1;
                $reasons[] = 'Tem chamado de suporte esperando resposta';
            }

            return [
                'company_id' => $c->id,
                'name' => $c->name,
                'plan' => $c->plan,
                'score' => $score,
                'level' => $score >= self::HIGH ? 'high' : ($score >= self::MEDIUM ? 'medium' : 'low'),
                'reasons' => $reasons,
            ];
        });
    }
}
