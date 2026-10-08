<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * Manda uma mensagem de texto a um cliente pelo WhatsApp conectado da loja. Só envia quando tudo
 * permite — Pro, WhatsApp conectado e telefone preenchido; nos outros casos devolve o motivo e a
 * tela cai no jeito antigo (abrir o wa.me com a mensagem pronta, ou copiar o texto).
 */
class WhatsAppMessenger
{
    public function __construct(private WahaClient $waha) {}

    /** @return array{sent: bool, reason: string|null}  reason: no_phone | not_connected | failed */
    public function deliver(Company $company, ?string $phone, string $message): array
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '') {
            return ['sent' => false, 'reason' => 'no_phone'];
        }

        if (! $company->isPro() || ! $company->whatsapp_session_name || ! $this->waha->isConfigured()) {
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
                $message
            );

            return ['sent' => true, 'reason' => null];
        } catch (\Throwable $e) {
            Log::warning("Mensagem pelo WhatsApp não enviada (empresa {$company->id}): ".$e->getMessage());

            return ['sent' => false, 'reason' => 'failed'];
        }
    }
}
