<?php

namespace App\Services;

use App\Models\Quote;

/** Manda o pedido de avaliação direto pelo WhatsApp conectado da loja (sem abrir o WhatsApp do lojista). */
class ReviewRequestNotifier
{
    public function __construct(private WhatsAppMessenger $messenger) {}

    /** @return array{sent: bool, reason: string|null}  reason: no_phone | not_connected | failed */
    public function send(Quote $quote, string $url): array
    {
        return $this->messenger->deliver($quote->company, $quote->customer?->phone, $this->message($quote, $url));
    }

    private function message(Quote $quote, string $url): string
    {
        $first = explode(' ', trim((string) $quote->customer?->name))[0];
        $store = $quote->company?->name ?: 'loja';

        return implode("\n", [
            'Olá'.($first !== '' ? ", {$first}" : '')."! Aqui é da {$store}. Gostaríamos de saber se ocorreu tudo certo com seu pedido de impressão 3D?",
            '',
            'Se puder, deixe sua avaliação — leva menos de um minuto e ajuda muito:',
            $url,
        ]);
    }
}
