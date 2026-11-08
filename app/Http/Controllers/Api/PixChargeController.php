<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentCharge;
use App\Models\Quote;
use App\Models\QuotePayment;
use App\Services\PixCharges;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Cobrança online da venda (Pro): GET lista as cobranças e o registro de pagamentos, POST gera um Pix ou um link
 * (cartão) do valor escolhido (tudo o que falta ou um sinal), GET /{charge} consulta a situação (a tela chama
 * enquanto está aberta), POST /{charge}/refund estorna (só o dono). Dono e vendas.
 */
class PixChargeController extends Controller
{
    public function __construct(private PixCharges $pix) {}

    public function index(Request $request, Quote $quote)
    {
        $this->authorizeCompany($request, $quote);
        $quote->loadMissing('company');

        $charges = $quote->charges()->limit(10)->get()
            ->map(fn (PaymentCharge $c) => $this->pix->present($c->status === PaymentCharge::STATUS_PENDING ? $this->pix->sync($c) : $c));
        $quote->refresh();

        return response()->json([
            'refusal' => $this->pix->refusal($quote),
            'final_price' => (float) $quote->final_price,
            'amount_paid' => (float) $quote->amount_paid,
            'amount_due' => $quote->amount_due,
            'validity_hours' => PixCharges::VALIDITY_HOURS,
            'charges' => $charges->values(),
            'payments' => $quote->payments()->with('user:id,name')->get()->map(fn (QuotePayment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'source' => $p->source,
                'note' => $p->note,
                'user' => $p->user?->name,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request, Quote $quote)
    {
        $this->authorizeCompany($request, $quote);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            // pix = QR + copia e cola; link = página do Mercado Pago (cartão parcelado ou Pix)
            'method' => ['sometimes', 'in:pix,link'],
        ]);

        try {
            $charge = $this->pix->create($quote, (float) $data['amount'], $request->user()->id, $data['method'] ?? PaymentCharge::METHOD_PIX);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($this->pix->present($charge), 201);
    }

    public function show(Request $request, Quote $quote, PaymentCharge $charge)
    {
        $this->authorizeCompany($request, $quote);
        abort_unless($charge->quote_id === $quote->id, 404);

        $charge = $this->pix->sync($charge);
        $quote->refresh();

        return response()->json($this->pix->present($charge) + [
            'quote' => ['amount_paid' => (float) $quote->amount_paid, 'amount_due' => $quote->amount_due, 'payment_status' => $quote->payment_status],
        ]);
    }

    /** Estorno pelo sistema (só o dono): devolve ao cliente pelo Mercado Pago e tira do recebido da venda. */
    public function refund(Request $request, Quote $quote, PaymentCharge $charge)
    {
        $this->authorizeCompany($request, $quote);
        abort_unless($charge->quote_id === $quote->id, 404);
        abort_unless($request->user()->isOwner(), 403, 'Só o dono da loja estorna pagamentos.');

        try {
            $charge = $this->pix->refund($charge);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($this->pix->present($charge));
    }

    private function authorizeCompany(Request $request, Quote $quote): void
    {
        abort_unless($quote->company_id === $request->user()->company_id, 404);
    }
}
