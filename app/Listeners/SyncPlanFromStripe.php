<?php

namespace App\Listeners;

use App\Models\Company;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Mantém companies.plan sincronizado com a assinatura na Stripe. Roda depois
 * que o próprio Cashier já atualizou a tabela local de assinaturas, então só
 * precisa reler `$company->subscription('default')` — sem reprocessar o JSON
 * cru do webhook.
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

        $company->update([
            'plan' => Company::planForSubscription($company->subscription('default')),
        ]);
    }
}
