<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\CashbackEntry;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\WhatsappHandoff;
use App\Services\CustomerMerger;
use App\Services\Cashback;
use App\Services\WhatsAppMessenger;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use EnforcesPlanLimits;

    public function index(Request $request)
    {
        return $request->user()->company->customers()->latest()->get();
    }

    public function store(Request $request)
    {
        $this->enforceFreeLimit($request, 'customers', $request->user()->company->customers()->count(), 'clientes');

        $data = $this->validated($request);
        $customer = $request->user()->company->customers()->create($data);

        return response()->json($customer, 201);
    }

    public function show(Request $request, Customer $customer)
    {
        $this->authorizeCompany($request, $customer);

        return $customer;
    }

    /**
     * Perfil interno do cliente: dados, vendas (itens e datas), total gasto e
     * último contato. "Compra" = venda aprovada e não cancelada; cortesia entra
     * na lista mas não conta como gasto.
     */
    public function profile(Request $request, Customer $customer)
    {
        $this->authorizeCompany($request, $customer);

        $quotes = Quote::where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->with('items')
            // Quantas avaliações a venda já recebeu — a tela só oferece "Pedir avaliação" enquanto não houver
            ->withCount('reviews')
            ->latest()
            ->get();

        $sales = $quotes->filter(fn (Quote $q) => $q->status === Quote::STATUS_APPROVED && ! $q->cancelled_at);
        $paying = $sales->filter(fn (Quote $q) => ! $q->is_courtesy);

        $saleDate = fn (Quote $q) => $q->approved_at ?? $q->created_at;

        $items = $sales
            ->flatMap(fn (Quote $q) => collect($q->soldLines())->map(fn ($line) => $line + ['date' => $saleDate($q)]))
            ->groupBy('description')
            ->map(fn ($rows, $description) => [
                'description' => $description,
                'quantity' => $rows->sum('quantity'),
                'amount' => round($rows->sum('amount'), 2),
                'last_purchase_at' => $rows->max('date')?->toIso8601String(),
            ])
            ->sortByDesc('quantity')
            ->values();

        // Último contato = a atividade mais recente ligada a esse cliente: pedido ou
        // orçamento criado, solicitação de orçamento do WhatsApp ou atendimento humano.
        $digitsKey = Customer::phoneKey($customer->phone);
        $handoffAt = $digitsKey === null ? null : WhatsappHandoff::where('company_id', $customer->company_id)
            ->get(['phone', 'updated_at'])
            ->filter(fn ($h) => Customer::phoneKey($h->phone) === $digitsKey)
            ->max('updated_at');
        $requestAt = QuoteRequest::where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->max('created_at');

        $lastContact = collect([$quotes->max('created_at'), $sales->map($saleDate)->max(), $requestAt ? \Illuminate\Support\Carbon::parse($requestAt) : null, $handoffAt])
            ->filter()
            ->max();

        return response()->json([
            'customer' => $customer,
            'stats' => [
                'orders_count' => $sales->count(),
                'total_spent' => round($paying->sum(fn ($q) => (float) $q->final_price), 2),
                'total_paid' => round($paying->sum(fn ($q) => (float) $q->amount_paid), 2),
                'total_due' => round($paying->sum(fn ($q) => (float) $q->amount_due), 2),
                'average_ticket' => $paying->count() ? round($paying->sum(fn ($q) => (float) $q->final_price) / $paying->count(), 2) : 0,
                'first_purchase_at' => $sales->map($saleDate)->min()?->toIso8601String(),
                'last_purchase_at' => $sales->map($saleDate)->max()?->toIso8601String(),
                'last_contact_at' => $lastContact?->toIso8601String(),
                'open_quotes_count' => $quotes->where('status', Quote::STATUS_SENT)->count(),
            ],
            'purchases' => $sales->values()->map(fn (Quote $q) => [
                'id' => $q->id,
                'date' => $saleDate($q)?->toIso8601String(),
                'total' => (float) $q->final_price,
                'amount_paid' => (float) $q->amount_paid,
                'payment_status' => $q->payment_status,
                'production_status' => $q->production_status,
                'is_courtesy' => (bool) $q->is_courtesy,
                'review_requested_at' => $q->review_requested_at?->toIso8601String(),
                'charge_requested_at' => $q->charge_requested_at?->toIso8601String(),
                'reviews_count' => (int) $q->reviews_count,
                'items' => $q->soldLines(),
            ]),
            'items_summary' => $items,
            // Link do Instagram da loja (Configurações → Catálogo) — habilita o botão "Enviar Instagram"
            'instagram_url' => $request->user()->company->catalog_instagram_url,
        ]);
    }

    /**
     * Convite pra seguir a loja no Instagram. Manda pelo WhatsApp conectado quando `send` e tudo permite;
     * senão devolve a mensagem pronta pra a tela abrir o wa.me (ou copiar).
     */
    public function instagramInvite(Request $request, Customer $customer, WhatsAppMessenger $messenger)
    {
        $this->authorizeCompany($request, $customer);

        $company = $request->user()->company;
        $url = trim((string) $company->catalog_instagram_url);
        abort_if($url === '', 422, 'Cadastre o link do Instagram em Configurações → Catálogo antes de enviar o convite.');

        $first = explode(' ', trim($customer->name))[0];
        $message = implode("\n", [
            'Olá'.($first !== '' ? ", {$first}" : '')."! Aqui é da {$company->name}.",
            '',
            'Que tal acompanhar a gente no Instagram? Lá mostramos novidades, bastidores da produção e promoções. Se puder, segue a gente:',
            $url,
        ]);

        $delivery = $request->boolean('send')
            ? $messenger->deliver($company, $customer->phone, $message)
            : ['sent' => false, 'reason' => null];

        return response()->json([
            'url' => $url,
            'message' => $message,
            'sent' => $delivery['sent'],
            'reason' => $delivery['reason'],
        ]);
    }

    /** Saldo e extrato de cashback do cliente (perfil do cliente). */
    public function cashback(Request $request, Customer $customer, Cashback $cashback)
    {
        $this->authorizeCompany($request, $customer);

        return response()->json($this->cashbackSummary($customer, $cashback));
    }

    /**
     * Saldo de cashback pelo telefone — usado pelo bot do WhatsApp (token de integração) pra responder
     * "quanto tenho de cashback?". Junta cadastros duplicados do mesmo telefone.
     */
    public function cashbackByPhone(Request $request, string $phone, Cashback $cashback)
    {
        $company = $request->user()->company;
        $key = Customer::phoneKey($phone);

        $ids = $key === null ? collect() : $company->customers()->whereNotNull('phone')->get(['id', 'phone'])
            ->filter(fn (Customer $c) => Customer::phoneKey($c->phone) === $key)
            ->pluck('id');

        $next = $ids->isEmpty() ? null : CashbackEntry::whereIn('customer_id', $ids)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->first();

        return response()->json([
            'active' => Cashback::isActive($company),
            'is_customer' => $ids->isNotEmpty(),
            'balance' => $ids->isEmpty() ? 0 : $cashback->balanceForCustomers($ids),
            'max_redeem_percent' => (float) ($company->setting?->cashback_max_redeem_percent ?? 0),
            // Pro bot mandar quando o cliente perguntar como indicar
            'referral_link' => $ids->isNotEmpty() && Cashback::isActive($company)
                ? $cashback->referralLink($company->customers()->whereIn('id', $ids)->orderBy('id')->first())
                : null,
            'referral_percent' => (float) ($company->setting?->cashback_referral_percent ?? 0),
            'next_expiring' => $next ? ['amount' => (float) $next->remaining, 'expires_at' => $next->expires_at->toDateString()] : null,
        ]);
    }

    /** Marca (ou tira) quem indicou o cliente — pra venda no balcão, onde não houve link. */
    public function setReferrer(Request $request, Customer $customer, Cashback $cashback)
    {
        $this->authorizeCompany($request, $customer);

        $data = $request->validate(['referrer_id' => ['nullable', 'integer']]);

        if (empty($data['referrer_id'])) {
            abort_if($customer->referral_rewarded_at, 422, 'A indicação já foi premiada e não pode ser removida.');
            $customer->forceFill(['referred_by_customer_id' => null])->save();
        } else {
            $referrer = $request->user()->company->customers()->find($data['referrer_id']);
            $error = $cashback->attachReferral($customer, $referrer);
            abort_if($error !== null, 422, $error);
        }

        return response()->json($this->cashbackSummary($customer->fresh(), $cashback));
    }

    /** Crédito ou débito manual (presente, correção). */
    public function adjustCashback(Request $request, Customer $customer, Cashback $cashback)
    {
        $this->authorizeCompany($request, $customer);
        abort_unless(Cashback::isActive($request->user()->company), 403, 'Ligue o cashback em Configurações (plano Pro) para ajustar o saldo.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-100000', 'max:100000'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $cashback->adjust($customer, (float) $data['amount'], $data['note'] ?? null, $request->user()->id);

        return response()->json($this->cashbackSummary($customer, $cashback));
    }

    private function cashbackSummary(Customer $customer, Cashback $cashback): array
    {
        $nextExpiring = CashbackEntry::where('customer_id', $customer->id)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->first();

        $active = Cashback::isActive($customer->company);
        $customer->loadMissing('referrer:id,name');

        return [
            'active' => $active,
            'balance' => $cashback->balance($customer),
            // Indicação: link do cliente, quem o indicou e quantas indicações dele já compraram
            'referral_link' => $active ? $cashback->referralLink($customer) : null,
            'referred_by' => $customer->referrer ? ['id' => $customer->referrer->id, 'name' => $customer->referrer->name] : null,
            'referral_rewarded' => $customer->referral_rewarded_at !== null,
            'referrals_count' => $customer->referrals()->whereNotNull('referral_rewarded_at')->count(),
            'next_expiring' => $nextExpiring ? [
                'amount' => (float) $nextExpiring->remaining,
                'expires_at' => $nextExpiring->expires_at->toIso8601String(),
            ] : null,
            'entries' => CashbackEntry::where('customer_id', $customer->id)
                ->with('user:id,name')
                ->latest('id')
                ->limit(50)
                ->get(['id', 'type', 'amount', 'expires_at', 'quote_id', 'user_id', 'note', 'created_at']),
        ];
    }

    /** Grupos de cadastros repetidos da empresa (mesmo telefone em formatos diferentes). */
    public function duplicates(Request $request, CustomerMerger $merger)
    {
        $groups = $merger->duplicateGroups($request->user()->company_id)
            ->map(function ($group) {
                $counts = Quote::whereIn('customer_id', $group->pluck('id'))->selectRaw('customer_id, count(*) as total')
                    ->groupBy('customer_id')->pluck('total', 'customer_id');

                return [
                    // O mais antigo é o que fica.
                    'keep_id' => $group->first()->id,
                    'customers' => $group->map(fn (Customer $c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'phone' => $c->phone,
                        'created_at' => $c->created_at?->toIso8601String(),
                        'quotes_count' => (int) ($counts[$c->id] ?? 0),
                    ])->values(),
                ];
            });

        return response()->json($groups->values());
    }

    /** Une cadastros repetidos: tudo de $merge_ids passa pra $keep_id (só se for o mesmo telefone). */
    public function merge(Request $request, CustomerMerger $merger)
    {
        $data = $request->validate([
            'keep_id' => ['required', 'integer'],
            'merge_ids' => ['required', 'array', 'min:1'],
            'merge_ids.*' => ['integer'],
        ]);

        $companyId = $request->user()->company_id;
        $keep = Customer::where('company_id', $companyId)->findOrFail($data['keep_id']);
        $extras = Customer::where('company_id', $companyId)->whereIn('id', $data['merge_ids'])->get();

        abort_unless($extras->count() === count(array_unique($data['merge_ids'])), 404, 'Cliente não encontrado.');
        abort_if(
            $extras->contains(fn (Customer $c) => Customer::phoneKey($c->phone) === null || Customer::phoneKey($c->phone) !== Customer::phoneKey($keep->phone)),
            422,
            'Só dá pra unir cadastros com o mesmo telefone.'
        );

        $merged = $merger->merge($keep, $extras);

        return response()->json(['merged' => $merged, 'customer' => $keep->fresh()]);
    }

    public function update(Request $request, Customer $customer)
    {
        $this->authorizeCompany($request, $customer);
        $customer->update($this->validated($request));

        return $customer;
    }

    public function destroy(Request $request, Customer $customer)
    {
        $this->authorizeCompany($request, $customer);
        $customer->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function authorizeCompany(Request $request, Customer $customer): void
    {
        abort_unless($customer->company_id === $request->user()->company_id, 403);
    }
}
