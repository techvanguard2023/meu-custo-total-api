<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\PaymentReminder;
use Illuminate\Http\Request;

/** Botão "Cobrar" das vendas entregues a receber (card da produção, Vendas e perfil do cliente). */
class QuoteChargeController extends Controller
{
    public function __construct(private PaymentReminder $reminder) {}

    /** O que a janela de cobrança precisa: as cobranças em aberto do cliente e a forma de pagamento da loja. */
    public function show(Request $request, Quote $quote)
    {
        abort_unless($quote->company_id === $request->user()->company_id, 404);
        abort_unless(PaymentReminder::isChargeable($quote), 422, 'Só dá pra cobrar venda entregue que ainda tem valor a receber.');

        $company = $request->user()->company;
        $quote->loadMissing('customer');

        return response()->json([
            'customer' => $quote->customer ? [
                'id' => $quote->customer->id,
                'name' => $quote->customer->name,
                'phone' => $quote->customer->phone,
            ] : null,
            'open' => $this->reminder->openCharges($quote)->map(fn (Quote $q) => [
                'id' => $q->id,
                'name' => trim(explode('|__JSON__|', (string) $q->name)[0]),
                'final_price' => (float) $q->final_price,
                'amount_paid' => (float) $q->amount_paid,
                'amount_due' => $q->amount_due,
                'charge_requested_at' => $q->charge_requested_at?->toIso8601String(),
            ])->values(),
            'payment' => [
                'pix_key' => $company->whatsapp_pix_key,
                'payment_link' => $company->whatsapp_payment_link,
            ],
        ]);
    }

    /**
     * Envia a cobrança de uma ou mais vendas do mesmo cliente. `send`: manda pelo WhatsApp conectado; sem
     * isso (ou se não der), a tela abre o wa.me com o `message` pronto.
     */
    public function send(Request $request)
    {
        $data = $request->validate([
            'quote_ids' => ['required', 'array', 'min:1', 'max:50'],
            'quote_ids.*' => ['integer', 'distinct'],
            'send' => ['sometimes', 'boolean'],
        ]);

        $company = $request->user()->company;
        $quotes = Quote::where('company_id', $company->id)->whereIn('id', $data['quote_ids'])->with('customer')->orderBy('id')->get();

        abort_unless($quotes->count() === count($data['quote_ids']), 404, 'Venda não encontrada.');
        abort_unless($quotes->every(fn (Quote $q) => PaymentReminder::isChargeable($q)), 422, 'Só dá pra cobrar venda entregue que ainda tem valor a receber.');
        abort_unless(
            $quotes->pluck('customer_id')->unique()->count() === 1 && ($quotes->first()->customer_id || $quotes->count() === 1),
            422,
            'Só dá pra juntar cobranças do mesmo cliente.'
        );

        $message = $this->reminder->message($company, $quotes);
        $phone = $quotes->first()->customer?->phone;

        $delivery = $request->boolean('send')
            ? $this->reminder->send($company, $phone, $message)
            : ['sent' => false, 'reason' => null];

        Quote::whereIn('id', $quotes->pluck('id'))->update(['charge_requested_at' => now()]);

        return response()->json([
            'sent' => $delivery['sent'],
            'reason' => $delivery['reason'],
            'message' => $this->reminder->singleText($message),
            'phone' => $phone,
            'total' => $message['total'],
        ]);
    }
}
