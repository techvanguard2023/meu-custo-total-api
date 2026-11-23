<?php

namespace App\Listeners;

use App\Models\Company;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Cobrança da assinatura recusada ou paga (faturas da Stripe). Escuta o WebhookReceived porque o Cashier não tem
 * handler para invoice.payment_failed (o WebhookHandled só sai para os eventos que ele trata).
 *  - invoice.payment_failed: conta a tentativa (attempt_count). A loja segue no plano enquanto a Stripe tenta de
 *    novo; na 3ª recusa (Company::PAYMENT_MAX_ATTEMPTS) é suspensa.
 *  - invoice.paid / invoice.payment_succeeded: zera as recusas — suspensa por falta de pagamento, volta na hora.
 * No painel da Stripe o endpoint precisa enviar invoice.payment_failed e invoice.paid; sem eles, o comando
 * billing:check-payments (de hora em hora) consulta as faturas das assinaturas em atraso.
 */
class TrackSubscriptionPayments
{
    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? '';
        if (! in_array($type, ['invoice.payment_failed', 'invoice.paid', 'invoice.payment_succeeded'], true)) {
            return;
        }

        $invoice = $event->payload['data']['object'] ?? [];
        // Só as faturas da assinatura (criação, renovação, troca de plano)
        if (! str_starts_with((string) ($invoice['billing_reason'] ?? ''), 'subscription') || empty($invoice['customer'])) {
            return;
        }
        $company = Company::where('stripe_id', $invoice['customer'])->first();
        if (! $company) {
            return;
        }

        if ($type === 'invoice.payment_failed') {
            $company->recordPaymentFailure((int) ($invoice['attempt_count'] ?? 1));
        } elseif ($company->payment_failed_at !== null || $company->payment_failed_attempts > 0) {
            $company->clearPaymentFailure();
        }
    }
}
