<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\WhatsappHandoff;
use Illuminate\Support\Facades\Log;

/**
 * Passa uma conversa do bot para o atendimento humano: o bot fica calado nela
 * por algumas horas e o atendente é avisado no próprio número do bot.
 */
class HumanHandoff
{
    public const PAUSE_HOURS = 4;

    /** "Até eu reativar": pausa manual sem prazo — na prática, 10 anos. */
    public const INDEFINITE_YEARS = 10;

    public function __construct(private WahaClient $waha) {}

    /**
     * $indefinite: pausa sem prazo (pedida pela tela, até o lojista reativar).
     * $notify: avisa o atendente no número do bot — o bot pede (padrão); pausar pela
     * tela não precisa, quem pausou já está olhando o sistema.
     * $onlyExtend: o lojista respondeu pelo celular — renova as 4h, mas nunca encurta uma pausa
     * já existente (ex: a manual "até reativar").
     */
    public function start(Company $company, string $phone, ?string $customerName, ?string $reason, bool $indefinite = false, bool $notify = true, bool $onlyExtend = false): WhatsappHandoff
    {
        // Reaproveita a pausa existente mesmo se o telefone estiver escrito diferente
        // (com/sem 55, com/sem nono dígito) — senão teria duas pausas pro mesmo cliente.
        $key = Customer::phoneKey($phone);
        $existing = WhatsappHandoff::where('company_id', $company->id)->get()
            ->first(fn ($h) => $h->phone === $phone || ($key !== null && Customer::phoneKey($h->phone) === $key));

        if ($onlyExtend && $existing) {
            // Só renova o prazo (se for maior que o atual): mantém nome e motivo de quem já estava pausado.
            if (! $existing->isPaused() || $existing->paused_until->lt(now()->addHours(self::PAUSE_HOURS))) {
                $existing->update(['paused_until' => now()->addHours(self::PAUSE_HOURS)]);
            }

            return $existing;
        }

        $values = [
            'customer_name' => $customerName,
            'reason' => $reason,
            'paused_until' => $indefinite ? now()->addYears(self::INDEFINITE_YEARS) : now()->addHours(self::PAUSE_HOURS),
        ];

        if ($existing) {
            $existing->update($values);
            $handoff = $existing;
        } else {
            $handoff = WhatsappHandoff::create(['company_id' => $company->id, 'phone' => $phone] + $values);
        }

        if ($notify) {
            $this->alertAttendant($company, $handoff);
        }

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
