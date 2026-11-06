<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAudit;
use App\Services\PlatformFinance;
use App\Support\Background;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Financeiro da plataforma (área admin): MRR, cancelamentos, receita, custos e lucro. */
class FinanceController extends Controller
{
    public function __construct(private PlatformFinance $finance) {}

    public function index()
    {
        $months = $this->finance->monthly();
        $current = end($months);
        $previous = $months[count($months) - 2] ?? null;
        $mrr = $this->finance->mrr();
        $lastRate = Cache::get('usd_brl_rate_last');

        // A tela só lê o que está guardado (nunca espera a Stripe ou a cotação). Faltou preço de plano ou a cotação
        // venceu: atualiza em segundo plano e o número certo aparece no próximo carregamento.
        $stale = $mrr['missing_prices'] || ! Cache::has('usd_brl_rate');
        if ($stale && Cache::add('platform_finance_quick_refresh', true, now()->addMinutes(10))) {
            Background::run(static function () {
                $finance = app(PlatformFinance::class);
                $finance->refreshPrices();
                $finance->usdRate(fresh: true);
            });
        }

        return response()->json([
            'mrr' => $mrr['mrr'],
            'arr' => round($mrr['mrr'] * 12, 2),
            'paying' => $mrr['paying'],
            'arpu' => $mrr['paying'] ? round($mrr['mrr'] / $mrr['paying'], 2) : 0,
            'churn' => $this->finance->churn(now()->format('Y-m')),
            'churn_previous' => $this->finance->churn(now()->subMonth()->format('Y-m')),
            'current' => $current,
            'previous' => $previous,
            'months' => $months,
            'recent_payments' => DB::table('platform_payments')
                ->leftJoin('companies', 'companies.id', '=', 'platform_payments.company_id')
                ->latest('paid_at')->limit(15)
                ->get(['platform_payments.*', 'companies.name AS company_name'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'company_id' => $p->company_id,
                    'company_name' => $p->company_name,
                    'amount' => (float) $p->amount,
                    'amount_refunded' => (float) $p->amount_refunded,
                    'fee' => $p->fee !== null ? (float) $p->fee : null,
                    'description' => $p->description,
                    'receipt_url' => $p->receipt_url,
                    'paid_at' => \Illuminate\Support\Carbon::parse($p->paid_at)->toIso8601String(),
                ]),
            'costs' => DB::table('platform_costs')->orderBy('name')->get(),
            'sources' => [
                'openai_configured' => (bool) config('services.openai.admin_key'),
                'usd_rate' => $this->finance->usdRate(network: false),
                // Assinaturas cujo preço ainda não foi lido na Stripe (o MRR sai sem elas até a atualização)
                'mrr_pending_prices' => count($mrr['missing_prices']),
                'usd_rate_at' => $lastRate['at'] ?? null,
                'synced_at' => Cache::get('platform_finance_synced_at'),
                'payments_total' => DB::table('platform_payments')->count(),
            ],
        ]);
    }

    /** "Atualizar agora": busca na Stripe, na OpenAI e a cotação (primeira vez: 13 meses). */
    public function sync(Request $request)
    {
        $full = DB::table('platform_payments')->doesntExist();
        $result = $this->finance->syncAll($full);
        AdminAudit::log($request, 'finance.sync', null, null, ['full' => $full]);

        return response()->json(['result' => $result] + $this->index()->getData(true));
    }

    public function storeCost(Request $request)
    {
        $id = DB::table('platform_costs')->insertGetId($this->validatedCost($request) + ['created_at' => now(), 'updated_at' => now()]);
        AdminAudit::log($request, 'finance.cost_create', null, null, ['id' => $id, 'name' => $request->input('name')]);

        return response()->json(DB::table('platform_costs')->find($id), 201);
    }

    public function updateCost(Request $request, int $cost)
    {
        abort_unless(DB::table('platform_costs')->where('id', $cost)->exists(), 404);
        DB::table('platform_costs')->where('id', $cost)->update($this->validatedCost($request) + ['updated_at' => now()]);
        AdminAudit::log($request, 'finance.cost_update', null, null, ['id' => $cost, 'name' => $request->input('name')]);

        return response()->json(DB::table('platform_costs')->find($cost));
    }

    public function destroyCost(Request $request, int $cost)
    {
        DB::table('platform_costs')->where('id', $cost)->delete();
        AdminAudit::log($request, 'finance.cost_delete', null, null, ['id' => $cost]);

        return response()->noContent();
    }

    /** Sem "desde", o custo começa no mês atual — senão entraria em todos os meses pra trás e distorceria o lucro. */
    private function validatedCost(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', Rule::in(['BRL', 'USD'])],
            'frequency' => ['required', Rule::in(['monthly', 'yearly'])],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $data['starts_on'] ??= now()->startOfMonth()->toDateString();

        return $data;
    }
}
