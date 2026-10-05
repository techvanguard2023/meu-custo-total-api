<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\WhatsappHandoff;
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
            ->latest()
            ->get();

        $sales = $quotes->filter(fn (Quote $q) => $q->status === Quote::STATUS_APPROVED && ! $q->cancelled_at);
        $paying = $sales->filter(fn (Quote $q) => ! $q->is_courtesy);

        $saleDate = fn (Quote $q) => $q->approved_at ?? $q->created_at;

        $items = $sales
            ->flatMap(fn (Quote $q) => $q->items->map(fn ($i) => [
                'description' => $i->description,
                'quantity' => (int) $i->quantity,
                'amount' => (float) $i->amount,
                'date' => $saleDate($q),
            ]))
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
                'items' => $q->items->map(fn ($i) => [
                    'description' => $i->description,
                    'quantity' => (int) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'amount' => (float) $i->amount,
                ])->values(),
            ]),
            'items_summary' => $items,
        ]);
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
