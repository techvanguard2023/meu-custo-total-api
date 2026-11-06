<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Quote;
use App\Services\ChurnRisk;
use App\Services\PlatformFeedbackStats;
use App\Services\ProTrialStats;
use App\Services\SignupChannelStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Visão geral da plataforma: lojas por plano, novas, ativas, assinaturas e uso dos recursos. */
class OverviewController extends Controller
{
    public function __invoke()
    {
        $since30 = now()->subDays(30);
        $since7 = now()->subDays(7);

        $byPlan = Company::query()->whereNull('deletion_scheduled_for')
            ->selectRaw('plan, COUNT(*) AS total')->groupBy('plan')->pluck('total', 'plan');

        $activeSince = fn (Carbon $since) => DB::table('users')->where('last_seen_at', '>=', $since)->distinct()->count('company_id');

        // Assinaturas na Stripe (tabela local do Cashier): situação de cada uma
        $subscriptions = DB::table('subscriptions')->where('type', 'default')
            ->selectRaw('stripe_status, stripe_price, COUNT(*) AS total')->groupBy('stripe_status', 'stripe_price')->get();
        $priceToPlan = [config('services.stripe.price_essential') => Company::PLAN_ESSENTIAL, config('services.stripe.price_pro') => Company::PLAN_PRO];
        $paying = ['essential' => 0, 'pro' => 0];
        foreach ($subscriptions->whereIn('stripe_status', ['active', 'trialing', 'past_due']) as $row) {
            $paying[$priceToPlan[$row->stripe_price] ?? Company::PLAN_PRO] += $row->total;
        }

        // Novas lojas por semana (12 semanas)
        $weeks = collect(range(11, 0))->map(function ($ago) {
            $start = now()->startOfWeek()->subWeeks($ago);

            return [
                'week' => $start->toDateString(),
                'signups' => Company::whereBetween('created_at', [$start, $start->copy()->endOfWeek()])->count(),
            ];
        })->values();

        return response()->json([
            'companies' => [
                'total' => (int) $byPlan->sum(),
                'by_plan' => [
                    'free' => (int) ($byPlan['free'] ?? 0),
                    'essential' => (int) ($byPlan['essential'] ?? 0),
                    'pro' => (int) ($byPlan['pro'] ?? 0),
                ],
                'new_7d' => Company::where('created_at', '>=', $since7)->count(),
                'new_30d' => Company::where('created_at', '>=', $since30)->count(),
                'active_7d' => $activeSince($since7),
                'active_30d' => $activeSince($since30),
                'comp' => Company::whereNotNull('comp_plan')->count(),
                'blocked' => Company::whereNotNull('blocked_at')->count(),
                'pending_deletion' => Company::whereNotNull('deletion_scheduled_for')->count(),
            ],
            'subscriptions' => [
                'paying' => $paying,
                'past_due' => (int) $subscriptions->where('stripe_status', 'past_due')->sum('total'),
                'trialing' => (int) $subscriptions->where('stripe_status', 'trialing')->sum('total'),
                'canceling' => DB::table('subscriptions')->where('type', 'default')->whereNotNull('ends_at')->where('ends_at', '>', now())->count(),
            ],
            // Uso dos recursos nos últimos 30 dias (todas as lojas)
            'usage_30d' => [
                'quotes' => Quote::where('created_at', '>=', $since30)->count(),
                'sales' => Quote::where('status', Quote::STATUS_APPROVED)->where('approved_at', '>=', $since30)->count(),
                'bot_orders' => Quote::whereNotNull('external_reference')->where('created_at', '>=', $since30)->count(),
                'catalog_visits' => DB::table('catalog_visits')->where('visited_at', '>=', $since30)->count(),
                'offer_posts' => DB::table('offer_posts')->where('created_at', '>=', $since30)->count(),
                'coupon_orders' => Quote::whereNotNull('coupon_id')->where('created_at', '>=', $since30)->count(),
            ],
            'signups_by_week' => $weeks,
            // Teste grátis do Pro: em andamento, conversão (no teste / depois com a oferta), ativação e coortes
            'pro_trial' => app(ProTrialStats::class)->summary(),
            // De onde vêm as lojas (90 dias): cadastros, assinaturas e teste por canal; campanhas do Google Ads
            'signup_channels' => app(SignupChannelStats::class)->summary(),
            // Opinião das lojas: NPS (90 dias), motivos de quem não assinou depois do teste, últimos comentários
            'platform_feedback' => app(PlatformFeedbackStats::class)->summary(),
            // Pagantes com risco de cancelar (sinais: acesso, orçamentos, NPS, suporte, pagamento)
            'churn_risk' => app(ChurnRisk::class)->list(),
            // Indique e ganhe: cadastros por indicação (90 dias) e quantos já renderam recompensa
            'referrals' => [
                'signups_90d' => Company::whereNotNull('referred_by_company_id')->where('created_at', '>=', now()->subDays(90))->count(),
                'rewarded_total' => Company::whereNotNull('referral_rewarded_at')->where('referral_reward->type', '!=', 'none')->count(),
            ],
            // Último acesso só começa a ser registrado com a área admin — antes disso fica vazio
            'tracking_since' => DB::table('users')->min('last_seen_at'),
        ]);
    }
}
