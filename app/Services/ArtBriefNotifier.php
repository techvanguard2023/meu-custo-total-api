<?php

namespace App\Services;

use App\Models\ArtBrief;

/** Avisos do briefing da arte: o link pro cliente e o "respondido" pra loja, pelo WhatsApp conectado da loja. */
class ArtBriefNotifier
{
    public function __construct(private WhatsAppMessenger $messenger) {}

    /** @return array{sent: bool, reason: string|null}  reason: no_phone | not_connected | failed */
    public function sendToCustomer(ArtBrief $brief): array
    {
        $brief->loadMissing(['quote.customer', 'company']);
        $first = explode(' ', trim((string) $brief->quote->customer?->name))[0];
        $store = $brief->company->name ?: 'loja';

        $message = implode("\n", [
            'Olá'.($first !== '' ? ", {$first}" : '').'!',
            "Aqui é da {$store}.",
            '',
            'Para criarmos a arte do seu pedido do jeitinho que você imagina, responda estas perguntas rápidas (leva uns 2 minutos):',
            $brief->publicUrl(),
            '',
            'Qualquer dúvida é só chamar por aqui.',
        ]);

        return $this->messenger->deliver($brief->company, $brief->quote->customer?->phone, $message);
    }

    /** Aviso pra própria loja quando o cliente envia as respostas. */
    public function notifyStore(ArtBrief $brief): bool
    {
        $brief->loadMissing(['quote.customer', 'company']);
        $who = trim((string) $brief->quote->customer?->name) ?: 'O cliente';
        $order = trim(explode('|__JSON__|', (string) $brief->quote->name)[0]) ?: "Pedido #{$brief->quote_id}";
        $link = $brief->company->publicOrigin().'/quotes/'.$brief->quote_id;

        return $this->messenger->notifyStore($brief->company, "📝 {$who} respondeu o briefing da arte do pedido \"{$order}\".\n{$link}");
    }
}
