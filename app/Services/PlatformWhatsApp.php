<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PlatformSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp da plataforma (número do Meu Custo Total, sessão PLATFORM_WAHA_SESSION) pro dono de uma loja: mensagem
 * do admin, avisos do teste grátis. Devolve o que aconteceu em texto, pra guardar e mostrar na área admin.
 *
 * Antes de enviar confere se a sessão está conectada: com ela caída (FAILED, aguardando QR...) a WAHA não responde
 * ao envio e a requisição só morria no tempo-limite, com o erro do cURL na ficha da loja.
 */
class PlatformWhatsApp
{
    /** Envio roda em segundo plano (ninguém espera na tela): dá mais tempo que os 8s do envio padrão. */
    private const SEND_TIMEOUT = 25;

    public function __construct(private WahaClient $waha) {}

    /** Sessão do número da plataforma e a situação dela (null = não configurada). Usada também em Admin → Sistema. */
    public function session(): ?string
    {
        $session = self::sessionName();

        return $session !== null && $this->waha->isConfigured() ? $session : null;
    }

    /** Nome da sessão: a criada pelo painel (Admin → Sistema) ou, sem ela, PLATFORM_WAHA_SESSION do .env. */
    public static function sessionName(): ?string
    {
        $session = trim((string) (PlatformSetting::get(PlatformSetting::PLATFORM_WAHA_SESSION) ?: config('services.platform.support_waha_session')));

        return $session !== '' ? $session : null;
    }

    /** De onde vem o nome: "panel" (criada no painel), "env" (.env) ou null (nenhuma). */
    public static function sessionSource(): ?string
    {
        if (trim((string) PlatformSetting::get(PlatformSetting::PLATFORM_WAHA_SESSION)) !== '') {
            return 'panel';
        }

        return trim((string) config('services.platform.support_waha_session')) !== '' ? 'env' : null;
    }

    /** @return array{status: string, problem: string|null}|null  null = não configurada */
    public function health(): ?array
    {
        if (! $session = $this->session()) {
            return null;
        }
        $info = $this->waha->getSession($session);

        return ['status' => (string) ($info['status'] ?? 'NÃO EXISTE NA WAHA'), 'problem' => WahaClient::problem($info)];
    }

    /** @return array{sent: bool, status: string} */
    public function sendToOwner(Company $company, string $text): array
    {
        if (! $session = $this->session()) {
            return ['sent' => false, 'status' => 'Não enviado: WhatsApp da plataforma não configurado (PLATFORM_WAHA_SESSION)'];
        }

        $owner = $company->users()->where('role', 'owner')->orderBy('id')->first();
        if (! $owner?->phone) {
            return ['sent' => false, 'status' => 'Não enviado: o dono não tem telefone no cadastro'];
        }

        try {
            if ($down = $this->sessionProblem($session)) {
                return ['sent' => false, 'status' => $down];
            }

            $chatId = $this->waha->chatIdFor($session, $owner->phone);
            if (! $chatId) {
                return ['sent' => false, 'status' => "Não enviado: {$owner->phone} não tem WhatsApp"];
            }
            // Prévia só quando a mensagem tem link (a WAHA busca a página para montar a prévia)
            $this->waha->sendText($session, $chatId, $text, str_contains($text, 'http'), self::SEND_TIMEOUT);

            return ['sent' => true, 'status' => "Enviado para {$owner->name} ({$owner->phone})"];
        } catch (ConnectionException $e) {
            Log::warning("WhatsApp da plataforma: sem resposta ao enviar para a loja {$company->id}: ".$e->getMessage());

            return ['sent' => false, 'status' => 'Sem confirmação: o WhatsApp da plataforma demorou demais para responder. A mensagem pode ter chegado — confira no celular antes de reenviar.'];
        } catch (Throwable $e) {
            Log::warning("WhatsApp da plataforma: falha ao enviar para a loja {$company->id}: ".$e->getMessage());

            return ['sent' => false, 'status' => 'Falhou: '.$e->getMessage()];
        }
    }

    /** Aviso interno pra equipe da plataforma (números de PLATFORM_SUPPORT_WHATSAPP). Devolve quantos receberam. */
    public function sendToPlatformTeam(string $text): int
    {
        $session = $this->session();
        $numbers = config('services.platform.support_whatsapp', []);
        if (! $session || ! $numbers) {
            return 0;
        }

        try {
            if ($down = $this->sessionProblem($session)) {
                Log::warning("WhatsApp da plataforma: aviso pra equipe não enviado — {$down}");

                return 0;
            }
        } catch (Throwable $e) {
            Log::warning('WhatsApp da plataforma: WAHA não respondeu: '.$e->getMessage());

            return 0;
        }

        $sent = 0;
        foreach ($numbers as $number) {
            try {
                $chatId = $this->waha->chatIdFor($session, $number);
                if ($chatId) {
                    $this->waha->sendText($session, $chatId, $text, false, self::SEND_TIMEOUT);
                    $sent++;
                }
            } catch (Throwable $e) {
                Log::warning("WhatsApp da plataforma: aviso pra {$number} não enviado: ".$e->getMessage());
            }
        }

        return $sent;
    }

    /** null = conectada. Senão, o motivo em texto para a ficha da loja. */
    private function sessionProblem(string $session): ?string
    {
        $info = $this->waha->getSession($session);
        if (($info['status'] ?? null) === 'WORKING') {
            return null;
        }

        return 'Não enviado: o WhatsApp da plataforma está fora do ar ('.($info['status'] ?? 'sessão não existe').' — '
            .WahaClient::problem($info).'). Veja em Admin → Sistema.';
    }
}
