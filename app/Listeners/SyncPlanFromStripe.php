<?php

namespace App\Listeners;

use App\Models\Company;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Mantém companies.plan sincronizado com o status da assinatura na Stripe.
 * Pagamento confirmado → pro; cancelamento/inadimplência → free.
 */
class SyncPlanFromStripe
{
    private const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;

        if (! in_array($payload['type'] ?? '', self::SUBSCRIPTION_EVENTS, true)) {
            return;
        }

        $stripeCustomerId = $payload['data']['object']['customer'] ?? null;
        if (! $stripeCustomerId) {
            return;
        }

        $company = Company::where('stripe_id', $stripeCustomerId)->first();
        if (! $company) {
            return;
        }

        $status = $payload['data']['object']['status'] ?? null;
        $isActive = $payload['type'] !== 'customer.subscription.deleted'
            && in_array($status, ['active', 'trialing'], true);

        $company->update([
            'plan' => $isActive ? Company::PLAN_PRO : Company::PLAN_FREE,
        ]);
    }
}
