<?php

namespace App\Listeners;

use App\Services\PlatformFinance;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;
use Throwable;

/** Cobrança paga ou reembolsada na Stripe: atualiza o financeiro da área admin na hora (com a taxa). */
class SyncPlatformPayment
{
    private const EVENTS = ['charge.succeeded', 'charge.refunded', 'charge.updated'];

    public function handle(WebhookHandled $event): void
    {
        $object = $event->payload['data']['object'] ?? [];
        if (! in_array($event->payload['type'] ?? '', self::EVENTS, true) || empty($object['id'])) {
            return;
        }

        try {
            app(PlatformFinance::class)->syncCharge($object['id']);
        } catch (Throwable $e) {
            // O comando diário (admin:sync-finance) pega de novo
            Log::warning("Financeiro da plataforma: cobrança {$object['id']} não gravada: {$e->getMessage()}");
        }
    }
}
