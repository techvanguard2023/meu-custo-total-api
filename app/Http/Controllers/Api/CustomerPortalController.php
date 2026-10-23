<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashbackEntry;
use App\Models\Company;
use App\Models\Quote;
use App\Services\Cashback;
use App\Services\CustomerPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * "Meus pedidos" no catálogo público: o cliente entra com telefone + código recebido no WhatsApp
 * (ver CustomerPortal) e acompanha os pedidos dele naquela loja. Rotas sem autenticação do painel.
 */
class CustomerPortalController extends Controller
{
    private const STAGE_LABELS = [
        Quote::PRODUCTION_PENDING => 'Pedido recebido',
        Quote::PRODUCTION_IN_PRODUCTION => 'Em produção',
        Quote::PRODUCTION_FINISHED => 'Pronto',
        Quote::PRODUCTION_DELIVERED => 'Entregue',
    ];

    public function __construct(private CustomerPortal $portal) {}

    public function requestCode(Request $request, string $token)
    {
        $company = $this->company($token);
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $wait = $this->portal->requestCode($company, $data['phone'], $request->ip());
        abort_if($wait !== null, 429, $wait);

        // Mesma resposta pra cliente e não cliente — a tela não revela quem compra na loja
        return response()->json(['message' => 'Se esse número tiver pedidos aqui, você vai receber um código no WhatsApp.']);
    }

    public function verifyCode(Request $request, string $token)
    {
        $company = $this->company($token);
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $session = $this->portal->verifyCode($company, $data['phone'], $data['code']);
        abort_unless($session, 422, 'Código inválido ou expirado. Confira o código ou peça outro.');

        return response()->json(['token' => $session]);
    }

    public function orders(Request $request, string $token)
    {
        $company = $this->company($token);
        $phoneKey = $this->portal->phoneKeyForToken($company, $request->header('X-Customer-Token'));
        abort_unless($phoneKey, 401, 'Sua sessão expirou. Entre de novo com o código do WhatsApp.');

        $customerIds = $this->portal->customerIds($company, $phoneKey);
        $customer = $company->customers()->whereIn('id', $customerIds)->orderBy('id')->first();

        $quotes = Quote::where('company_id', $company->id)
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status', [Quote::STATUS_APPROVED, Quote::STATUS_CANCELLED])
            ->with(['items', 'stageEvents'])
            ->withCount('reviews')
            ->orderByDesc('id')
            ->get();

        // O bot separa num pedido só a parte pronta e a sob encomenda (mesma referência): pro cliente é um pedido
        $orders = $quotes
            ->groupBy(fn (Quote $q) => $q->external_reference ?: 'q'.$q->id)
            ->map(fn (Collection $group) => $this->orderPayload($company, $group->sortBy('id')->values()))
            ->values();

        return response()->json([
            'customer_name' => $customer ? explode(' ', trim($customer->name))[0] : null,
            'orders' => $orders,
            // Pra pagar o que falta direto da tela do pedido
            'payment' => array_filter([
                'pix_key' => $company->whatsapp_pix_key,
                'payment_link' => $company->whatsapp_payment_link,
            ]),
            'cashback' => $this->cashbackPayload($company, $customerIds),
        ]);
    }

    /** Saldo e extrato do cashback (só quando a loja tem o programa ligado). */
    private function cashbackPayload(Company $company, Collection $customerIds): ?array
    {
        if (! Cashback::isActive($company)) {
            return null;
        }

        $setting = $company->setting;
        $next = CashbackEntry::whereIn('customer_id', $customerIds)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->first();

        return [
            'balance' => app(Cashback::class)->balanceForCustomers($customerIds),
            'purchase_percent' => (float) $setting->cashback_purchase_percent,
            'max_redeem_percent' => (float) $setting->cashback_max_redeem_percent,
            'next_expiring' => $next ? ['amount' => (float) $next->remaining, 'expires_at' => $next->expires_at->toIso8601String()] : null,
            'entries' => CashbackEntry::whereIn('customer_id', $customerIds)
                ->latest('id')
                ->limit(20)
                ->get(['id', 'type', 'amount', 'expires_at', 'quote_id', 'note', 'created_at']),
        ];
    }

    /** Saldo de cashback de quem está logado — o carrinho do catálogo oferece usar. */
    public function cashback(Request $request, string $token)
    {
        $company = $this->company($token);
        $phoneKey = $this->portal->phoneKeyForToken($company, $request->header('X-Customer-Token'));
        abort_unless($phoneKey, 401, 'Sua sessão expirou. Entre de novo com o código do WhatsApp.');

        if (! Cashback::isActive($company)) {
            return response()->json(['balance' => 0, 'max_redeem_percent' => 0]);
        }

        return response()->json([
            'balance' => app(Cashback::class)->balanceForCustomers($this->portal->customerIds($company, $phoneKey)),
            'max_redeem_percent' => (float) $company->setting->cashback_max_redeem_percent,
        ]);
    }

    public function logout(Request $request, string $token)
    {
        $this->portal->logout($this->company($token), $request->header('X-Customer-Token'));

        return response()->noContent();
    }

    private function orderPayload(Company $company, Collection $quotes): array
    {
        $active = $quotes->filter(fn (Quote $q) => $q->status !== Quote::STATUS_CANCELLED);
        $paying = $active->reject(fn (Quote $q) => $q->is_courtesy);
        $total = round($paying->sum(fn ($q) => (float) $q->final_price), 2);
        $paid = round($paying->sum(fn ($q) => (float) $q->amount_paid), 2);
        $due = round($paying->sum(fn ($q) => (float) $q->amount_due), 2);

        $paymentStatus = match (true) {
            $active->isEmpty() => 'cancelled',
            $paying->isEmpty() => 'courtesy',
            $due <= 0 => 'paid',
            $paid > 0 => 'partial',
            default => 'unpaid',
        };

        return [
            'key' => $quotes->first()->external_reference ?: 'q'.$quotes->first()->id,
            'number' => $quotes->map(fn (Quote $q) => '#'.$q->id)->implode(' + '),
            'date' => $quotes->map(fn (Quote $q) => $q->approved_at ?? $q->created_at)->min()?->toIso8601String(),
            'total' => $total,
            'paid' => $paid,
            'due' => $due,
            'payment_status' => $paymentStatus,
            'parts' => $quotes->map(fn (Quote $q) => $this->partPayload($company, $q, $quotes->count() > 1))->values(),
        ];
    }

    private function partPayload(Company $company, Quote $quote, bool $isSplit): array
    {
        $stages = array_keys(self::STAGE_LABELS);
        $currentIndex = array_search($quote->production_status, $stages, true);
        $cancelled = $quote->status === Quote::STATUS_CANCELLED;

        // Data em que entrou em cada etapa (a mais recente, se voltou e avançou de novo)
        $reachedAt = $quote->stageEvents->groupBy('stage')->map(fn ($events) => $events->max('created_at'));
        $firstStage = $quote->stageEvents->sortBy('id')->first()?->stage;

        return [
            'id' => $quote->id,
            // Pedido dividido: identifica cada parte pelo jeito que nasceu (já pronta ou pra produzir)
            'label' => $isSplit
                ? (in_array($firstStage, [Quote::PRODUCTION_FINISHED, Quote::PRODUCTION_DELIVERED], true) ? 'Itens a pronta entrega' : 'Itens sob encomenda')
                : null,
            'cancelled' => $cancelled,
            'production_status' => $quote->production_status,
            'timeline' => collect($stages)->map(fn ($stage, $i) => [
                'stage' => $stage,
                'label' => self::STAGE_LABELS[$stage],
                'done' => ! $cancelled && $currentIndex !== false && $i <= $currentIndex,
                'current' => ! $cancelled && $i === $currentIndex,
                'at' => isset($reachedAt[$stage]) ? \Illuminate\Support\Carbon::parse($reachedAt[$stage])->toIso8601String() : null,
            ])->values(),
            'items' => $quote->soldLines(),
            // Entregue e ainda sem avaliação: o cliente avalia direto daqui
            'review_url' => $quote->production_status === Quote::PRODUCTION_DELIVERED && $quote->review_token && $quote->reviews_count === 0
                ? $company->publicOrigin().'/avaliar/'.$quote->review_token
                : null,
        ];
    }

    /** Mesma regra do catálogo público (slug ou token antigo, catálogo ligado) + área disponível (Pro com WhatsApp). */
    private function company(string $token): Company
    {
        $company = Company::where('slug', $token)->orWhere('catalog_token', $token)->first();
        abort_unless($company && $company->hasCatalogActive() && CustomerPortal::isAvailable($company), 404, 'Área do cliente indisponível.');

        return $company;
    }
}
