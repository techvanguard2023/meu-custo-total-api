<?php

namespace App\Services;

use App\Models\Company;
use App\Models\WhatsappHandoff;
use Illuminate\Support\Facades\Log;

/**
 * Passa uma conversa do bot para o atendimento humano: o bot fica calado nela
 * por algumas horas e o atendente é avisado no próprio número do bot.
 */
class HumanHandoff
{
    public const PAUSE_HOURS = 4;

    public function __construct(private WahaClient $waha) {}

    public function start(Company $company, string $phone, ?string $customerName, ?string $reason): WhatsappHandoff
    {
        $handoff = WhatsappHandoff::updateOrCreate(
            ['company_id' => $company->id, 'phone' => $phone],
            [
                'customer_name' => $customerName,
                'reason' => $reason,
                'paused_until' => now()->addHours(self::PAUSE_HOURS),
            ]
        );

        $this->alertAttendant($company, $handoff);

        return $handoff;
    }

    /** Aviso pro próprio número do bot ("mensagem para você") — falha aqui nunca derruba o handoff. */
    private function alertAttendant(Company $company, WhatsappHandoff $handoff): void
    {
        try {
            if (! $company->whatsapp_session_name || ! $this->waha->isConfigured()) {
                return;
            }

            $session = $this->waha->getSession($company->whatsapp_session_name);
            $self = $session['me']['id'] ?? null;
            if (($session['status'] ?? null) !== 'WORKING' || ! $self) {
                return;
            }

            $until = $handoff->paused_until->copy()->timezone($company->timezone ?: 'America/Sao_Paulo')->format('H:i');

            $text = "*Atendimento humano solicitado*\n"
                .'Cliente: '.($handoff->customer_name ?: 'não informado')."\n"
                ."Conversa: https://wa.me/{$handoff->phone}\n"
                .($handoff->reason ? "Motivo: {$handoff->reason}\n" : '')
                .'O bot fica pausado nessa conversa por '.self::PAUSE_HOURS."h (até {$until}).";

            $this->waha->sendText($company->whatsapp_session_name, $self, $text);
        } catch (\Throwable $e) {
            Log::warning('Aviso de atendimento humano não enviado: '.$e->getMessage());
        }
    }
}
