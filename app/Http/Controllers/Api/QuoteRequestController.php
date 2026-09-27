<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Concerns\FindsCustomerByPhone;
use App\Http\Controllers\Controller;
use App\Models\QuoteRequest;
use App\Services\QuotePdfNotifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Solicitações de orçamento de peça sob medida vindas do bot de WhatsApp —
 * chegam sem preço, aguardando o vendedor avaliar e montar o orçamento de
 * verdade na tela de Orçamentos.
 */
class QuoteRequestController extends Controller
{
    use EnforcesPlanLimits;
    use FindsCustomerByPhone;

    public function index(Request $request)
    {
        return $request->user()->company->quoteRequests()
            ->with(['customer', 'quote'])
            ->latest()
            ->get();
    }

    public function show(Request $request, QuoteRequest $quoteRequest)
    {
        $this->authorizeCompany($request, $quoteRequest);

        return $quoteRequest->load(['customer', 'quote']);
    }

    /** Descartar (ou reabrir) uma solicitação que não vai virar orçamento. */
    public function update(Request $request, QuoteRequest $quoteRequest)
    {
        $this->authorizeCompany($request, $quoteRequest);

        $data = $request->validate([
            'status' => [Rule::in([QuoteRequest::STATUS_PENDING, QuoteRequest::STATUS_DISCARDED])],
        ]);

        $quoteRequest->update($data);

        return response()->json($quoteRequest->fresh()->load(['customer', 'quote']));
    }

    public function destroy(Request $request, QuoteRequest $quoteRequest)
    {
        $this->authorizeCompany($request, $quoteRequest);

        $quoteRequest->delete();

        return response()->json(null, 204);
    }

    /**
     * Vincula o orçamento (já precificado pelo vendedor na tela normal de
     * Orçamentos) à solicitação e dispara o envio do PDF pelo WhatsApp.
     */
    public function linkQuote(Request $request, QuoteRequest $quoteRequest)
    {
        $this->authorizeCompany($request, $quoteRequest);

        $data = $request->validate([
            'quote_id' => [
                'required', 'integer',
                Rule::exists('quotes', 'id')->where('company_id', $request->user()->company_id),
            ],
        ]);

        $quoteRequest->update(['quote_id' => $data['quote_id'], 'status' => QuoteRequest::STATUS_QUOTED]);

        app(QuotePdfNotifier::class)->notify($data['quote_id']);

        return response()->json($quoteRequest->fresh()->load(['customer', 'quote']));
    }

    /** Chamado pelo bot de WhatsApp quando termina de coletar item, cor(es) e quantidade com o cliente. */
    public function externalStore(Request $request)
    {
        $this->requirePro($request, 'Orçamentos por WhatsApp');

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'item_description' => ['required', 'string', 'max:255'],
            'colors' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'photo_urls' => ['sometimes', 'nullable', 'array', 'max:5'],
            'photo_urls.*' => ['url', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Identifica a mensagem do lado do bot — reenviar com a mesma referência
            // devolve a solicitação já criada, em vez de duplicar.
            'external_reference' => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($data['external_reference'])) {
            $existing = $request->user()->company->quoteRequests()
                ->where('external_reference', $data['external_reference'])
                ->with(['customer', 'quote'])
                ->first();

            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        $customer = $this->findOrCreateCustomerByPhone($request, $data['customer_name'], $data['customer_phone']);

        $quoteRequest = $request->user()->company->quoteRequests()->create([
            'customer_id' => $customer->id,
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'],
            'item_description' => $data['item_description'],
            'colors' => $data['colors'] ?? null,
            'quantity' => $data['quantity'],
            'photo_urls' => $data['photo_urls'] ?? [],
            'notes' => $data['notes'] ?? null,
            'external_reference' => $data['external_reference'] ?? null,
        ]);

        return response()->json($quoteRequest->load('customer'), 201);
    }

    private function authorizeCompany(Request $request, QuoteRequest $quoteRequest): void
    {
        abort_unless($quoteRequest->company_id === $request->user()->company_id, 403);
    }
}
