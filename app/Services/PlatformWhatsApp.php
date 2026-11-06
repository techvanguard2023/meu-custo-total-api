<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp da plataforma (número do Meu Custo Total, sessão PLATFORM_WAHA_SESSION) pro dono de uma loja: mensagem
 * do admin, avisos do teste grátis. Devolve o que aconteceu em texto, pra guardar e mostrar na área admin.
 */
class PlatformWhatsApp
{
    public function __construct(private WahaClient $waha) {}

    /** @return array{sent: bool, status: string} */
    public function sendToOwner(Company $company, string $text): array
    {
        $session = (string) config('services.platform.support_waha_session');
        if ($session === '' || ! $this->waha->isConfigured()) {
            return ['sent' => false, 'status' => 'Não enviado: WhatsApp da plataforma não configurado (PLATFORM_WAHA_SESSION)'];
        }

        $owner = $company->users()->where('role', 'owner')->orderBy('id')->first();
        if (! $owner?->phone) {
            return ['sent' => false, 'status' => 'Não enviado: o dono não tem telefone no cadastro'];
        }

        try {
            $chatId = $this->waha->chatIdFor($session, $owner->phone);
            if (! $chatId) {
                return ['sent' => false, 'status' => "Não enviado: {$owner->phone} não tem WhatsApp"];
            }
            $this->waha->sendText($session, $chatId, $text, true);

            return ['sent' => true, 'status' => "Enviado para {$owner->name} ({$owner->phone})"];
        } catch (Throwable $e) {
            return ['sent' => false, 'status' => 'Falhou: '.$e->getMessage()];
        }
    }

    /** Aviso interno pra equipe da plataforma (números de PLATFORM_SUPPORT_WHATSAPP). Devolve quantos receberam. */
    public function sendToPlatformTeam(string $text): int
    {
        $session = (string) config('services.platform.support_waha_session');
        $numbers = config('services.platform.support_whatsapp', []);
        if ($session === '' || ! $numbers || ! $this->waha->isConfigured()) {
            return 0;
        }

        $sent = 0;
        foreach ($numbers as $number) {
            try {
                $chatId = $this->waha->chatIdFor($session, $number);
                if ($chatId) {
                    $this->waha->sendText($session, $chatId, $text);
                    $sent++;
                }
            } catch (Throwable $e) {
                Log::warning("WhatsApp da plataforma: aviso pra {$number} não enviado: ".$e->getMessage());
            }
        }

        return $sent;
    }
}
