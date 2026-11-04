<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Quote;
use App\Services\AdminAudit;
use App\Services\WahaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/** Lojas da plataforma: lista, ficha e ações (cortesia, bloqueio, anotações). Toda ação fica na auditoria. */
class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'plan' => ['nullable', Rule::in(['free', 'essential', 'pro'])],
            'status' => ['nullable', Rule::in(['blocked', 'comp', 'pending_deletion', 'past_due', 'whatsapp'])],
            'sort' => ['nullable', Rule::in(['created', 'last_seen', 'name'])],
        ]);

        $since30 = now()->subDays(30);
        $query = Company::query()
            ->withCount('users')
            ->withCount(['quotes as quotes_30d' => fn ($q) => $q->where('created_at', '>=', $since30)])
            ->addSelect([
                'last_seen_at' => DB::table('users')->selectRaw('MAX(last_seen_at)')->whereColumn('users.company_id', 'companies.id'),
                'owner_email' => DB::table('users')->select('email')->whereColumn('users.company_id', 'companies.id')->where('role', 'owner')->orderBy('id')->limit(1),
                'subscription_status' => DB::table('subscriptions')->select('stripe_status')->whereColumn('subscriptions.company_id', 'companies.id')->where('type', 'default')->latest('id')->limit(1),
            ]);

        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('users', fn ($u) => $u->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")));
        }
        if (! empty($data['plan'])) {
            $query->where('plan', $data['plan']);
        }
        match ($data['status'] ?? null) {
            'blocked' => $query->whereNotNull('blocked_at'),
            'comp' => $query->whereNotNull('comp_plan'),
            'pending_deletion' => $query->whereNotNull('deletion_scheduled_for'),
            'past_due' => $query->whereHas('subscriptions', fn ($s) => $s->where('stripe_status', 'past_due')),
            'whatsapp' => $query->whereNotNull('whatsapp_session_name'),
            default => null,
        };
        match ($data['sort'] ?? 'created') {
            'last_seen' => $query->orderByRaw('last_seen_at IS NULL, last_seen_at DESC'),
            'name' => $query->orderBy('name'),
            default => $query->latest('id'),
        };

        return $query->paginate(25)->through(fn (Company $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'logo_url' => $c->logo_url,
            'plan' => $c->plan,
            'comp_plan' => $c->comp_plan,
            'comp_until' => $c->comp_until?->toDateString(),
            'blocked' => $c->blocked_at !== null,
            'pending_deletion' => $c->deletion_scheduled_for !== null,
            'subscription_status' => $c->subscription_status,
            'whatsapp' => (bool) $c->whatsapp_session_name,
            'owner_email' => $c->owner_email,
            'users_count' => $c->users_count,
            'quotes_30d' => $c->quotes_30d,
            'last_seen_at' => $c->last_seen_at ? \Illuminate\Support\Carbon::parse($c->last_seen_at)->toIso8601String() : null,
            'created_at' => $c->created_at?->toIso8601String(),
        ]);
    }

    public function show(Company $company, WahaClient $waha)
    {
        $since30 = now()->subDays(30);
        $subscription = $company->subscription('default');

        // WhatsApp: consulta rápida da sessão (sem derrubar a ficha se a WAHA não responder)
        $whatsappStatus = null;
        if ($company->whatsapp_session_name && $waha->isConfigured()) {
            try {
                $whatsappStatus = $waha->getSession($company->whatsapp_session_name)['status'] ?? 'NÃO ENCONTRADA';
            } catch (Throwable) {
                $whatsappStatus = 'SEM RESPOSTA';
            }
        }

        $stripeMode = str_starts_with((string) config('cashier.secret'), 'sk_test') ? 'test/' : '';

        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'email' => $company->email,
                'phone' => $company->phone,
                'segment' => $company->segment,
                'logo_url' => $company->logo_url,
                'plan' => $company->plan,
                'created_at' => $company->created_at?->toIso8601String(),
                'catalog_url' => $company->hasCatalogActive() ? $company->catalogUrl() : null,
                'custom_domain' => $company->custom_domain,
                'comp_plan' => $company->comp_plan,
                'comp_until' => $company->comp_until?->toDateString(),
                'comp_note' => $company->comp_note,
                'blocked_at' => $company->blocked_at?->toIso8601String(),
                'blocked_reason' => $company->blocked_reason,
                'deletion_scheduled_for' => $company->deletion_scheduled_for?->toIso8601String(),
                'admin_notes' => $company->admin_notes,
                'whatsapp_session' => $company->whatsapp_session_name,
                'whatsapp_status' => $whatsappStatus,
            ],
            'subscription' => $subscription ? [
                'status' => $subscription->stripe_status,
                'plan' => Company::planForSubscription($subscription),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
                'created_at' => $subscription->created_at?->toIso8601String(),
            ] : null,
            'stripe_url' => $company->stripe_id ? "https://dashboard.stripe.com/{$stripeMode}customers/{$company->stripe_id}" : null,
            'users' => $company->users()->orderByRaw("role = 'owner' DESC")->orderBy('id')->get()->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'last_seen_at' => $u->last_seen_at?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
                'terms_accepted' => $u->terms_accepted_at !== null,
            ]),
            'usage' => [
                'products' => $company->products()->count(),
                'customers' => $company->customers()->count(),
                'quotes_total' => $company->quotes()->count(),
                'quotes_30d' => $company->quotes()->where('created_at', '>=', $since30)->count(),
                'sales_30d' => $company->quotes()->where('status', Quote::STATUS_APPROVED)->where('approved_at', '>=', $since30)->count(),
                'sales_value_30d' => round((float) $company->quotes()->where('status', Quote::STATUS_APPROVED)->where('approved_at', '>=', $since30)->sum('final_price'), 2),
                'bot_orders_30d' => $company->quotes()->whereNotNull('external_reference')->where('created_at', '>=', $since30)->count(),
                'catalog_visits_30d' => DB::table('catalog_visits')->where('company_id', $company->id)->where('visited_at', '>=', $since30)->count(),
                'offer_posts_30d' => DB::table('offer_posts')->where('company_id', $company->id)->where('created_at', '>=', $since30)->count(),
            ],
            // Saúde: o que falhou nos envios automáticos e quando o bot/o painel foram usados (7 dias)
            'health' => $this->health($company),
            // Pagamentos da loja na Stripe (receita que ela trouxe pra plataforma)
            'payments' => [
                'total' => round((float) DB::table('platform_payments')->where('company_id', $company->id)->sum(DB::raw('amount - amount_refunded')), 2),
                'recent' => DB::table('platform_payments')->where('company_id', $company->id)->latest('paid_at')->limit(12)
                    ->get(['amount', 'amount_refunded', 'description', 'receipt_url', 'paid_at'])
                    ->map(fn ($p) => ['amount' => (float) $p->amount, 'refunded' => (float) $p->amount_refunded, 'description' => $p->description, 'receipt_url' => $p->receipt_url, 'paid_at' => \Illuminate\Support\Carbon::parse($p->paid_at)->toIso8601String()]),
            ],
            'audit' => DB::table('admin_audit_logs')->where('company_id', $company->id)->latest('id')->limit(20)
                ->get(['action', 'admin_email', 'details', 'created_at'])
                ->map(fn ($a) => ['action' => $a->action, 'admin' => $a->admin_email, 'details' => json_decode((string) $a->details, true), 'at' => \Illuminate\Support\Carbon::parse($a->created_at)->toIso8601String()]),
        ]);
    }

    /**
     * "Entrar como" a loja: token do dono que vale 1 hora, com o nome "impersonation:{admin}" — o app mostra a
     * faixa de aviso, toda alteração vai pra auditoria e o acesso não conta como uso da loja.
     */
    public function impersonate(Request $request, Company $company)
    {
        abort_if($company->id === $request->user()->company_id, 422, 'Essa já é a sua loja.');
        $owner = $company->users()->where('role', 'owner')->orderBy('id')->first();
        abort_unless($owner, 422, 'Esta loja não tem um dono para acessar.');

        $expires = now()->addHour();
        $token = $owner->createToken('impersonation:'.$request->user()->id, ['*'], $expires)->plainTextToken;
        AdminAudit::log($request, 'impersonate.start', $company->id, $owner->id, ['email' => $owner->email]);

        return response()->json([
            'token' => $token,
            'user' => $owner->load('company')->setAttribute('impersonated_by', ['email' => $request->user()->email, 'expires_at' => $expires->toIso8601String()]),
            'expires_at' => $expires->toIso8601String(),
        ]);
    }

    /** Plano de cortesia: vale por cima da assinatura (o maior dos dois) até a data — sem data, até tirar. */
    public function setComp(Request $request, Company $company)
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in([Company::PLAN_ESSENTIAL, Company::PLAN_PRO])],
            'until' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['until.after_or_equal' => 'A data final precisa ser hoje ou depois.']);

        $from = $company->plan;
        $company->forceFill(['comp_plan' => $data['plan'], 'comp_until' => $data['until'] ?? null, 'comp_note' => $data['note'] ?? null])->save();
        $company->update(['plan' => $company->planFromBilling()]);
        AdminAudit::log($request, 'comp.set', $company->id, null, ['plan' => $data['plan'], 'until' => $data['until'] ?? null, 'note' => $data['note'] ?? null, 'plan_from' => $from, 'plan_to' => $company->plan]);

        return $this->show($company->fresh(), app(WahaClient::class));
    }

    public function removeComp(Request $request, Company $company)
    {
        $from = $company->plan;
        $company->forceFill(['comp_plan' => null, 'comp_until' => null, 'comp_note' => null])->save();
        $company->update(['plan' => $company->planFromBilling()]);
        AdminAudit::log($request, 'comp.remove', $company->id, null, ['plan_from' => $from, 'plan_to' => $company->plan]);

        return $this->show($company->fresh(), app(WahaClient::class));
    }

    /** Bloqueio: painel, bot, catálogo e envios automáticos param até desbloquear. */
    public function block(Request $request, Company $company)
    {
        abort_if($company->id === $request->user()->company_id, 422, 'Você não pode bloquear a sua própria loja.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $company->forceFill(['blocked_at' => now(), 'blocked_reason' => $data['reason']])->save();
        AdminAudit::log($request, 'company.block', $company->id, null, ['reason' => $data['reason']]);

        return $this->show($company->fresh(), app(WahaClient::class));
    }

    public function unblock(Request $request, Company $company)
    {
        $company->forceFill(['blocked_at' => null, 'blocked_reason' => null])->save();
        AdminAudit::log($request, 'company.unblock', $company->id);

        return $this->show($company->fresh(), app(WahaClient::class));
    }

    private function health(Company $company): array
    {
        $since = now()->subDays(7);
        $offers = DB::table('offer_deliveries')->where('company_id', $company->id)->where('created_at', '>=', $since)
            ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return [
            'last_seen_at' => ($last = DB::table('users')->where('company_id', $company->id)->max('last_seen_at')) ? \Illuminate\Support\Carbon::parse($last)->toIso8601String() : null,
            'last_bot_order_at' => ($bot = $company->quotes()->whereNotNull('external_reference')->max('created_at')) ? \Illuminate\Support\Carbon::parse($bot)->toIso8601String() : null,
            'offers_sent_7d' => (int) ($offers['sent'] ?? 0),
            'offers_failed_7d' => (int) ($offers['failed'] ?? 0),
            'offers_pending' => DB::table('offer_deliveries')->where('company_id', $company->id)->where('status', 'pending')->count(),
            'last_offer_error' => DB::table('offer_deliveries')->where('company_id', $company->id)->where('status', 'failed')->latest('id')->value('error'),
            'cashback_notices_failed_7d' => DB::table('cashback_notifications')->where('company_id', $company->id)->whereNotNull('failed_at')->where('failed_at', '>=', $since)->count(),
            'coupon_sends_failed_7d' => DB::table('coupons')->where('company_id', $company->id)->whereNotNull('automation')->whereNotNull('notify_error')->where('updated_at', '>=', $since)->count(),
        ];
    }

    public function notes(Request $request, Company $company)
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $company->forceFill(['admin_notes' => $data['notes'] ?? null])->save();
        AdminAudit::log($request, 'company.notes', $company->id);

        return response()->json(['admin_notes' => $company->admin_notes]);
    }
}
