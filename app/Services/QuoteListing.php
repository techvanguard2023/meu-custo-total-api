<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Quote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Listas de orçamentos e vendas com filtro, busca e paginação no banco (antes a tela baixava a história
 * inteira e filtrava no navegador), e os totais do Dashboard e das Vendas somados em SQL. Os números seguem
 * os mesmos critérios das telas: cortesia conta como venda mas fica fora de tudo que é dinheiro; a data da
 * venda é a da aprovação (ou a de criação, se não houver).
 */
class QuoteListing
{
    public const PER_PAGE = 10;

    /** "Entregue ao Cliente" na Produção mostra só as últimas entregas (o resto só conta). */
    public const DELIVERED_ON_BOARD = 10;

    /**
     * @param  array{search?: ?string, status?: ?string, from?: ?string, to?: ?string, page?: ?int}  $filters
     */
    public function quotes(Company $company, array $relations, array $filters): LengthAwarePaginator
    {
        $query = $company->quotes()->where('status', '!=', Quote::STATUS_APPROVED);
        $this->search($query, $filters['search'] ?? null);
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        [$from, $to] = $this->range($filters);
        if ($from) {
            $query->whereBetween('created_at', [$from, $to]);
        }

        return $query->with($relations)->withCount('reviews')->latest()->latest('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    /**
     * @param  array{search?: ?string, stage?: ?string, payment?: ?string, from?: ?string, to?: ?string, page?: ?int}  $filters
     */
    public function sales(Company $company, array $relations, array $filters): LengthAwarePaginator
    {
        $query = $company->quotes()->where('status', Quote::STATUS_APPROVED);
        $this->search($query, $filters['search'] ?? null);

        $stage = $filters['stage'] ?? 'all';
        if ($stage === Quote::PRODUCTION_PENDING) {
            $query->where(fn ($q) => $q->whereNull('production_status')->orWhere('production_status', Quote::PRODUCTION_PENDING));
        } elseif ($stage && $stage !== 'all') {
            $query->where('production_status', $stage);
        }

        // Mesma regra do Quote::paymentStatus (cortesia → nada pago → pago tudo → parcial)
        match ($filters['payment'] ?? 'all') {
            'courtesy' => $query->where('is_courtesy', true),
            'unpaid' => $query->where('is_courtesy', false)->whereRaw('COALESCE(amount_paid, 0) <= 0'),
            'paid' => $query->where('is_courtesy', false)->whereRaw('COALESCE(amount_paid, 0) > 0')->whereRaw('COALESCE(amount_paid, 0) >= COALESCE(final_price, 0)'),
            'partial' => $query->where('is_courtesy', false)->whereRaw('COALESCE(amount_paid, 0) > 0')->whereRaw('COALESCE(amount_paid, 0) < COALESCE(final_price, 0)'),
            default => null,
        };

        [$from, $to] = $this->range($filters);
        if ($from) {
            // A data da venda é a da aprovação (sem ela, a de criação) — como na tela e nos relatórios
            $query->where(fn ($q) => $q->whereBetween('approved_at', [$from, $to])
                ->orWhere(fn ($q) => $q->whereNull('approved_at')->whereBetween('created_at', [$from, $to])));
        }

        return $query->with($relations)->withCount('reviews')->latest()->latest('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    /** Cards do topo das Vendas: todas as vendas (sem os filtros da lista). */
    public function salesSummary(Company $company): array
    {
        $row = $company->quotes()->where('status', Quote::STATUS_APPROVED)->toBase()
            ->selectRaw('COUNT(*) AS sales_count')
            ->selectRaw('SUM(CASE WHEN is_courtesy THEN 0 ELSE 1 END) AS billable_count')
            ->selectRaw('SUM(CASE WHEN is_courtesy THEN 0 ELSE COALESCE(final_price, 0) END) AS revenue')
            ->selectRaw('SUM(CASE WHEN is_courtesy THEN 0 ELSE COALESCE(profit_amount, 0) END) AS profit_amount')
            ->selectRaw('SUM(CASE WHEN is_courtesy THEN 0 ELSE COALESCE(amount_paid, 0) END) AS received')
            ->selectRaw('SUM(CASE WHEN is_courtesy THEN 0 ELSE COALESCE(markup_percent, 0) END) AS markup_sum')
            ->first();

        $billable = (int) $row->billable_count;

        return [
            'sales_count' => (int) $row->sales_count,
            'billable_count' => $billable,
            'revenue' => round((float) $row->revenue, 2),
            // Chaves de custo/margem com o nome que o TeamAccess tira das respostas pra equipe
            'profit_amount' => round((float) $row->profit_amount, 2),
            'received' => round((float) $row->received, 2),
            'markup_percent' => $billable > 0 ? round((float) $row->markup_sum / $billable, 2) : 0,
        ];
    }

    /**
     * Quadro da Produção: todas as vendas em andamento e só as últimas entregas (a coluna "Entregue" cresce
     * pra sempre); o total de entregues vai junto pra tela mostrar "+N anteriores".
     */
    public function production(Company $company, array $relations): array
    {
        $approved = fn () => $company->quotes()->where('status', Quote::STATUS_APPROVED);

        $active = $approved()
            ->where(fn ($q) => $q->whereNull('production_status')->orWhere('production_status', '!=', Quote::PRODUCTION_DELIVERED))
            ->with($relations)->withCount('reviews')->orderBy('production_order')->orderBy('id')->get();
        $delivered = $approved()->where('production_status', Quote::PRODUCTION_DELIVERED)
            ->with($relations)->withCount('reviews')->orderByDesc('production_order')->orderByDesc('id')
            ->limit(self::DELIVERED_ON_BOARD)->get()->reverse();

        return [
            'data' => $active->concat($delivered)->values(),
            'delivered_total' => $approved()->where('production_status', Quote::PRODUCTION_DELIVERED)->count(),
        ];
    }

    /** Números do Dashboard do dono — os mesmos que a tela somava com a lista inteira. */
    public function dashboard(Company $company, array $relations): array
    {
        $row = $company->quotes()->toBase()
            ->selectRaw('COUNT(*) AS quotes_count')
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS sales_count")
            ->selectRaw("SUM(CASE WHEN status = 'approved' AND NOT is_courtesy THEN 1 ELSE 0 END) AS billable_count")
            ->selectRaw("SUM(CASE WHEN status = 'approved' AND NOT is_courtesy THEN COALESCE(final_price, 0) ELSE 0 END) AS revenue")
            ->selectRaw("SUM(CASE WHEN status = 'approved' AND NOT is_courtesy THEN COALESCE(profit_amount, 0) ELSE 0 END) AS profit_amount")
            ->selectRaw("SUM(CASE WHEN status = 'approved' AND NOT is_courtesy THEN COALESCE(amount_paid, 0) ELSE 0 END) AS received")
            ->selectRaw("SUM(CASE WHEN status = 'approved' AND NOT is_courtesy THEN COALESCE(markup_percent, 0) ELSE 0 END) AS markup_sum")
            ->selectRaw("SUM(CASE WHEN status = 'approved' AND is_courtesy THEN 1 ELSE 0 END) AS courtesy_count")
            ->selectRaw("SUM(CASE WHEN status = 'approved' AND is_courtesy THEN COALESCE(final_price, 0) ELSE 0 END) AS courtesy_value")
            ->selectRaw("SUM(CASE WHEN status IN ('sent', 'draft') THEN 1 ELSE 0 END) AS pending_count")
            ->selectRaw("SUM(CASE WHEN status IN ('sent', 'draft') THEN COALESCE(final_price, 0) ELSE 0 END) AS pending_value")
            // Cabeçalho do gráfico: lucro somado de todas as vendas / de todos os orçamentos (como a tela fazia)
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN COALESCE(profit_amount, 0) ELSE 0 END) AS sales_profit_all")
            ->selectRaw("SUM(CASE WHEN status <> 'approved' THEN COALESCE(profit_amount, 0) ELSE 0 END) AS quotes_profit_all")
            ->first();

        $revenue = round((float) $row->revenue, 2);
        $received = round((float) $row->received, 2);
        $billable = (int) $row->billable_count;
        $quotesCount = (int) $row->quotes_count;
        $salesCount = (int) $row->sales_count;

        // Gráfico: as 8 mais recentes de cada tipo, da mais antiga pra mais nova
        $chart = fn (bool $approved) => $company->quotes()
            ->where('status', $approved ? '=' : '!=', Quote::STATUS_APPROVED)
            ->latest()->latest('id')->limit(8)
            ->with('items:id,quote_id,type,unit_cost,quantity')
            ->get(['id', 'name', 'final_price', 'subtotal_cost', 'failure_cost', 'profit_amount'])
            ->reverse()->values()
            ->map(fn (Quote $q) => [
                'name' => $q->name,
                'final_price' => (float) $q->final_price,
                // A tela antiga lia um "total_cost" que não existe (o custo saía sempre zero)
                'total_cost' => $q->totalCost(),
                'profit_amount' => (float) $q->profit_amount,
            ]);

        return [
            'sales_count' => $salesCount,
            'billable_count' => $billable,
            'revenue' => $revenue,
            'profit_amount' => round((float) $row->profit_amount, 2),
            'received' => $received,
            'receivable' => round(max(0, $revenue - $received), 2),
            'received_percent' => $revenue > 0 ? round($received / $revenue * 100, 2) : 0,
            'markup_percent' => $billable > 0 ? round((float) $row->markup_sum / $billable, 2) : 0,
            'courtesy_count' => (int) $row->courtesy_count,
            'courtesy_value' => round((float) $row->courtesy_value, 2),
            'quotes_count' => $quotesCount,
            'pending_count' => (int) $row->pending_count,
            'pending_value' => round((float) $row->pending_value, 2),
            'conversion_rate' => $quotesCount > 0 ? round($salesCount / $quotesCount * 100, 2) : 0,
            'chart' => ['sales' => $chart(true), 'quotes' => $chart(false)],
            'chart_totals' => [
                'sales' => ['count' => $salesCount, 'profit_amount' => round((float) $row->sales_profit_all, 2)],
                'quotes' => ['count' => $quotesCount - $salesCount, 'profit_amount' => round((float) $row->quotes_profit_all, 2)],
            ],
            'recent' => $company->quotes()->with($relations)->withCount('reviews')->latest()->latest('id')->limit(5)->get()
                ->each(fn (Quote $q) => $q->setAttribute('total_cost', $q->totalCost())),
        ];
    }

    /**
     * Painel da equipe: vendas em produção por etapa, orçamentos abertos e o valor em negociação — em
     * "final_price", nome que o TeamAccess tira da resposta pra produção (que trabalha sem valores).
     */
    public function teamSummary(Company $company): array
    {
        $byStage = $company->quotes()->where('status', Quote::STATUS_APPROVED)
            ->whereNotNull('production_status')->where('production_status', '!=', Quote::PRODUCTION_DELIVERED)
            ->toBase()->selectRaw('production_status, COUNT(*) AS total')->groupBy('production_status')
            ->pluck('total', 'production_status')->map(fn ($n) => (int) $n);
        $open = $company->quotes()->whereIn('status', [Quote::STATUS_SENT, Quote::STATUS_DRAFT])->toBase()
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(final_price), 0) AS value')->first();

        return [
            'in_production' => $byStage->sum(),
            'by_stage' => (object) $byStage->all(),
            'open_quotes' => (int) $open->total,
            'final_price' => round((float) $open->value, 2),
        ];
    }

    /** Busca pelo nome do orçamento, do cliente, da impressora ou do material (como a tela fazia). */
    private function search(Builder|\Illuminate\Database\Eloquent\Relations\HasMany $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '\\%_').'%';
        $query->where(fn ($q) => $q->where('name', 'like', $like)
            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))
            ->orWhereHas('printer', fn ($c) => $c->where('name', 'like', $like))
            ->orWhereHas('material', fn ($c) => $c->where('name', 'like', $like)));
    }

    /** @return array{0: ?string, 1: ?string} período em UTC ("Y-m-d H:i:s"), ou [null, null] sem filtro */
    private function range(array $filters): array
    {
        if (empty($filters['from']) && empty($filters['to'])) {
            return [null, null];
        }

        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->utc() : Carbon::create(2000);
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->utc() : now()->utc();

        return [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
    }
}
