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

    /** @return array{sent: bool, reason: string|null}  reason: no_phone | not_connected | not_on_whatsapp | failed */
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

            $chatId = $this->waha->chatIdFor($company->whatsapp_session_name, $digits);
            if ($chatId === null) {
                return ['sent' => false, 'reason' => 'not_on_whatsapp'];
            }

            $this->waha->sendText($company->whatsapp_session_name, $chatId, $message);

            return ['sent' => true, 'reason' => null];
        } catch (\Throwable $e) {
            Log::warning("Mensagem pelo WhatsApp não enviada (empresa {$company->id}): ".$e->getMessage());

            return ['sent' => false, 'reason' => 'failed'];
        }
    }

    /**
     * Imagem com o texto como legenda. Se a imagem não passar (WAHA sem suporte, arquivo recusado),
     * manda só o texto — o cliente recebe a mensagem de qualquer jeito.
     *
     * @return array{sent: bool, reason: string|null}
     */
    public function deliverImage(Company $company, ?string $phone, ?string $jpeg, string $caption): array
    {
        if ($jpeg === null) {
            return $this->deliver($company, $phone, $caption);
        }

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

            $chatId = $this->waha->chatIdFor($company->whatsapp_session_name, $digits);
            if ($chatId === null) {
                return ['sent' => false, 'reason' => 'not_on_whatsapp'];
            }

            $this->waha->sendImage(
                $company->whatsapp_session_name,
                $chatId,
                $jpeg,
                'image/jpeg',
                'cashback.jpg',
                $caption
            );

            return ['sent' => true, 'reason' => null];
        } catch (\Throwable $e) {
            Log::warning("Imagem pelo WhatsApp não enviada, indo só o texto (empresa {$company->id}): ".$e->getMessage());

            return $this->deliver($company, $phone, $caption);
        }
    }

    /**
     * Aviso pra própria loja, no número conectado ("mensagem para você") — mesmo jeito do aviso de
     * atendimento humano. Falha aqui nunca derruba quem chamou.
     */
    public function notifyStore(Company $company, string $message): bool
    {
        if (! $company->isPro() || ! $company->whatsapp_session_name || ! $this->waha->isConfigured()) {
            return false;
        }

        try {
            $session = $this->waha->getSession($company->whatsapp_session_name);
            $self = $session['me']['id'] ?? null;
            if (($session['status'] ?? null) !== 'WORKING' || ! $self) {
                return false;
            }

            $this->waha->sendText($company->whatsapp_session_name, $self, $message);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Aviso pra loja não enviado (empresa {$company->id}): ".$e->getMessage());

            return false;
        }
    }
}
