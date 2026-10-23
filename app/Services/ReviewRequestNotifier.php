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

        $reward = app(Cashback::class)->reviewReward($quote);

        return implode("\n", array_filter([
            'Olá'.($first !== '' ? ", {$first}" : '')."! Aqui é da {$store}. Gostaríamos de saber se ocorreu tudo certo com seu pedido de impressão 3D?",
            '',
            'Se puder, deixe sua avaliação — leva menos de um minuto e ajuda muito:',
            $url,
            $reward > 0 ? '' : null,
            $reward > 0 ? 'Avaliando, você ganha *R$ '.number_format($reward, 2, ',', '.').' de cashback* para a próxima compra 🎁' : null,
        ], fn ($line) => $line !== null));
    }
}
