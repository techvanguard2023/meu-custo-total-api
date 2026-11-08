<?php

namespace App\Services;

use App\Models\Quote;
use App\Models\QuotePayment;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Registro de pagamentos das vendas. `quotes.amount_paid` continua sendo o total recebido (tudo no sistema lê
 * dele); cada mudança nesse total vira uma linha aqui, venha de onde vier (aprovação, Caixa, "Recebido",
 * expositor, Pix do Mercado Pago) — o model Quote chama recordChange() ao gravar.
 *
 * Quem sabe mais sobre o pagamento (forma, origem, id no provedor) usa withContext() em volta da gravação.
 */
class QuotePayments
{
    private static ?array $context = null;

    /** Grava com estes dados no registro: ['source' => ..., 'method' => ..., 'external_id' => ..., 'note' => ...]. */
    public static function withContext(array $context, Closure $work): mixed
    {
        $previous = self::$context;
        self::$context = $context;
        try {
            return $work();
        } finally {
            self::$context = $previous;
        }
    }

    /** Chamado pelo model Quote quando `amount_paid` muda (ou nasce diferente de zero). */
    public function recordChange(Quote $quote, float $before, float $after): void
    {
        $delta = round($after - $before, 2);
        if (abs($delta) < 0.005) {
            return;
        }
        $ctx = self::$context ?? [];

        QuotePayment::create([
            'company_id' => $quote->company_id,
            'quote_id' => $quote->id,
            'amount' => $delta,
            'method' => $ctx['method'] ?? $quote->payment_method,
            'source' => $ctx['source'] ?? ($quote->display_id ? QuotePayment::SOURCE_DISPLAY : QuotePayment::SOURCE_MANUAL),
            'external_id' => $ctx['external_id'] ?? null,
            'user_id' => array_key_exists('user_id', $ctx) ? $ctx['user_id'] : auth()->id(),
            'note' => $ctx['note'] ?? ($delta < 0 ? ($quote->is_courtesy ? 'Virou cortesia' : 'Valor recebido corrigido para menos') : null),
            'paid_at' => now(),
        ]);
    }

    /**
     * Pagamento confirmado pelo provedor (Pix pago): soma ao recebido, sem passar do total da venda. Devolve o
     * valor que entrou de fato (0 se a venda já estava quitada ou é cortesia). Avisa o cliente no WhatsApp.
     */
    public function addOnline(Quote $quote, float $amount, string $method, string $externalId, ?string $note = null): float
    {
        $applied = DB::transaction(function () use ($quote, $amount, $method, $externalId, $note) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);
            if ($quote->is_courtesy) {
                return 0.0;
            }
            $before = (float) $quote->amount_paid;
            $after = round(min((float) $quote->final_price, $before + $amount), 2);
            if ($after <= $before) {
                return 0.0;
            }
            $settled = $after >= (float) $quote->final_price;

            self::withContext(
                ['source' => QuotePayment::SOURCE_MERCADOPAGO, 'method' => $method, 'external_id' => $externalId, 'note' => $note, 'user_id' => null],
                fn () => $quote->update([
                    'amount_paid' => $after,
                    'paid_at' => $settled ? ($quote->paid_at ?? now()) : null,
                    // Venda sem forma de pagamento definida passa a ser a do Pix
                    'payment_method' => $quote->payment_method ?? $method,
                ]),
            );

            return round($after - $before, 2);
        });

        if ($applied > 0) {
            $quote->refresh();
            app(ProductionStageNotifier::class)->paymentChanged($quote, (float) $quote->amount_paid - $applied);
        }

        return $applied;
    }

    /** Estorno feito no provedor: tira do recebido o que tinha entrado por aquele pagamento. */
    public function removeOnline(Quote $quote, float $amount, string $externalId, string $note): void
    {
        DB::transaction(function () use ($quote, $amount, $externalId, $note) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);
            $after = round(max(0, (float) $quote->amount_paid - $amount), 2);
            self::withContext(
                ['source' => QuotePayment::SOURCE_MERCADOPAGO, 'external_id' => $externalId, 'note' => $note, 'user_id' => null],
                fn () => $quote->update(['amount_paid' => $after, 'paid_at' => $after >= (float) $quote->final_price ? $quote->paid_at : null]),
            );
        });
    }
}
