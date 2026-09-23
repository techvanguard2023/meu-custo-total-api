<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Services\WahaClient;
use Illuminate\Http\Request;

/**
 * Conexão do WhatsApp da empresa com o WAHA — uma sessão por empresa
 * ("empresa-{id}"), gerenciada por aqui. A conversa em si (receber mensagem,
 * responder) não passa por essa API: fica entre o WAHA e o fluxo no n8n.
 */
class WhatsAppConnectionController extends Controller
{
    use EnforcesPlanLimits;

    public function __construct(private WahaClient $waha) {}

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

        $session = $this->waha->ensureSessionStarted($sessionName);

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
