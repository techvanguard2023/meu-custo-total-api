<?php

namespace App\Services;

use App\Models\Quote;
use Illuminate\Support\Facades\Log;

/**
 * Manda o pedido de avaliação direto pelo WhatsApp conectado da loja (sem abrir o WhatsApp do
 * lojista). Só envia quando tudo permite — Pro, WhatsApp conectado e cliente com telefone; nos
 * outros casos o motivo volta pra tela, que cai no jeito antigo (abrir o wa.me ou copiar o link).
 */
class ReviewRequestNotifier
{
    public function __construct(private WahaClient $waha) {}

    /** @return array{sent: bool, reason: string|null}  reason: no_phone | not_connected | failed */
    public function send(Quote $quote, string $url): array
    {
        $company = $quote->company;
        $digits = preg_replace('/\D/', '', (string) $quote->customer?->phone);

        if ($digits === '') {
            return ['sent' => false, 'reason' => 'no_phone'];
        }

        if (! $company?->isPro() || ! $company->whatsapp_session_name || ! $this->waha->isConfigured()) {
            return ['sent' => false, 'reason' => 'not_connected'];
        }

        try {
            $session = $this->waha->getSession($company->whatsapp_session_name);
            if (($session['status'] ?? null) !== 'WORKING') {
                return ['sent' => false, 'reason' => 'not_connected'];
            }

            $this->waha->sendText(
                $company->whatsapp_session_name,
                (strlen($digits) <= 11 ? '55'.$digits : $digits).'@c.us',
                $this->message($quote, $url)
            );

            return ['sent' => true, 'reason' => null];
        } catch (\Throwable $e) {
            Log::warning("Pedido de avaliação não enviado (venda {$quote->id}): ".$e->getMessage());

            return ['sent' => false, 'reason' => 'failed'];
        }
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
