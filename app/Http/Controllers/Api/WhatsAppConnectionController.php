<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Services\WahaClient;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Conexão do WhatsApp da empresa com o WAHA — uma sessão por empresa
 * ("empresa-{id}"), gerenciada por aqui. A conversa em si (receber mensagem,
 * responder) não passa por essa API: fica entre o WAHA e o fluxo no n8n.
 */
class WhatsAppConnectionController extends Controller
{
    use EnforcesPlanLimits;

    /** Eventos que o lojista pode marcar pro webhook — chave da WAHA => rótulo. */
    public const WEBHOOK_EVENTS = [
        'message' => 'Mensagem recebida',
        'message.any' => 'Qualquer mensagem (inclusive as enviadas por você)',
        'message.ack' => 'Confirmação de entrega e leitura',
        'message.reaction' => 'Reação a uma mensagem',
        'message.revoked' => 'Mensagem apagada',
        'message.edited' => 'Mensagem editada',
        'session.status' => 'Mudança de status da conexão',
        'presence.update' => 'Presença do contato (online, digitando)',
        'call.received' => 'Ligação recebida',
    ];

    public function __construct(private WahaClient $waha) {}

    /**
     * Configurações do bot da empresa. O n8n lê aqui (com o token de integração)
     * o prompt e os dados de pagamento; a tela de Integrações lê e edita.
     */
    public function settings(Request $request)
    {
        $this->requirePro($request, 'Conexão com WhatsApp');

        return response()->json($this->settingsPayload($request->user()->company));
    }

    public function updateSettings(Request $request)
    {
        $this->requirePro($request, 'Conexão com WhatsApp');

        $data = $request->validate([
            'webhook_url' => ['nullable', 'url', 'max:2048'],
            'webhook_events' => ['nullable', 'array'],
            'webhook_events.*' => ['string', Rule::in(array_keys(self::WEBHOOK_EVENTS))],
            'bot_prompt' => ['nullable', 'string', 'max:10000'],
            'payment_link' => ['nullable', 'url', 'max:2048'],
            'pix_key' => ['nullable', 'string', 'max:1000'],
        ]);

        $company = $request->user()->company;

        $webhookChanged = $request->hasAny(['webhook_url', 'webhook_events']);

        $company->update([
            'whatsapp_webhook_url' => $data['webhook_url'] ?? null,
            // Com URL e nenhum evento marcado, cai no padrão: só mensagens recebidas.
            'whatsapp_webhook_events' => ! empty($data['webhook_url'])
                ? (array_values($data['webhook_events'] ?? []) ?: ['message'])
                : null,
            'whatsapp_bot_prompt' => $data['bot_prompt'] ?? null,
            'whatsapp_payment_link' => $data['payment_link'] ?? null,
            'whatsapp_pix_key' => $data['pix_key'] ?? null,
        ]);

        // Sessão já criada: aplica o novo webhook na hora. Se a WAHA estiver fora,
        // o valor fica salvo e é reaplicado na próxima vez que conectar.
        if ($webhookChanged && $company->whatsapp_session_name && $this->waha->isConfigured()) {
            try {
                $this->waha->updateWebhooks($company->whatsapp_session_name, $this->webhooksFor($company->fresh()));
            } catch (\Throwable $e) {
                Log::warning('Falha ao atualizar webhook na WAHA: '.$e->getMessage());
            }
        }

        return response()->json($this->settingsPayload($company->fresh()));
    }

    private function settingsPayload(Company $company): array
    {
        return [
            'webhook_url' => $company->whatsapp_webhook_url,
            'webhook_events' => $company->whatsapp_webhook_events ?? [],
            'available_events' => collect(self::WEBHOOK_EVENTS)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'bot_prompt' => $company->whatsapp_bot_prompt,
            'payment_link' => $company->whatsapp_payment_link,
            'pix_key' => $company->whatsapp_pix_key,
        ];
    }

    /** Formato que a WAHA espera; vazio quando o lojista não configurou webhook. */
    private function webhooksFor(Company $company): array
    {
        if (! $company->whatsapp_webhook_url) {
            return [];
        }

        return [[
            'url' => $company->whatsapp_webhook_url,
            'events' => $company->whatsapp_webhook_events ?: ['message'],
        ]];
    }

    /** Status atual — a tela faz polling nele enquanto aguarda o QR ser escaneado. */
    public function show(Request $request)
    {
        $this->requirePro($request, 'Conexão com WhatsApp');

        $company = $request->user()->company;

        if (! $this->waha->isConfigured()) {
            return response()->json(['status' => 'unavailable']);
        }

        if (! $company->whatsapp_session_name) {
            return response()->json(['status' => 'not_connected']);
        }

        $session = $this->waha->getSession($company->whatsapp_session_name);

        if (! $session) {
            // A sessão sumiu do lado da WAHA (ex: apagada manualmente) — limpa a referência.
            $company->update(['whatsapp_session_name' => null]);

            return response()->json(['status' => 'not_connected']);
        }

        return response()->json($this->payload($session));
    }

    /** Cria (ou reinicia) a sessão da empresa e devolve o estado pra tela buscar o QR. */
    public function connect(Request $request)
    {
        $this->requirePro($request, 'Conexão com WhatsApp');
        abort_unless($this->waha->isConfigured(), 422, 'Integração com WhatsApp ainda não configurada.');

        $company = $request->user()->company;
        // O slug já é único e sem espaço/acento (mesmo usado na URL do catálogo) —
        // gravado uma única vez: se a empresa trocar o slug depois, a sessão já
        // criada continua com o nome antigo, sem quebrar nada.
        $sessionName = $company->whatsapp_session_name ?: $company->slug;

        $session = $this->waha->ensureSessionStarted($sessionName, $this->webhooksFor($company));

        if (! $company->whatsapp_session_name) {
            $company->update(['whatsapp_session_name' => $sessionName]);
        }

        return response()->json($this->payload($session));
    }

    /** Desloga o WhatsApp — reconectar depois pede um QR code novo. */
    public function disconnect(Request $request)
    {
        $this->requirePro($request, 'Conexão com WhatsApp');

        $company = $request->user()->company;
        abort_unless($company->whatsapp_session_name, 422, 'Nenhuma conexão ativa.');

        $this->waha->logoutSession($company->whatsapp_session_name);

        return response()->json(['status' => 'not_connected']);
    }

    private function payload(array $session): array
    {
        // STOPPED é o estado de "desconectado por aqui" (ver logoutSession) —
        // a tela não precisa saber que por baixo é um estado da WAHA.
        $status = ($session['status'] ?? null) === 'STOPPED' ? 'not_connected' : ($session['status'] ?? 'unknown');

        return [
            'status' => $status,
            'phone' => $session['me']['id'] ?? null,
            'qr' => $status === 'SCAN_QR_CODE' ? $this->waha->getQrCodeDataUri($session['name']) : null,
        ];
    }
}
