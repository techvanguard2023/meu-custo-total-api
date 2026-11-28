<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Services\AdminAudit;
use App\Services\PlatformWhatsApp;
use App\Services\WahaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin → Sistema → WhatsApp da plataforma: o número do Meu Custo Total (suporte, mensagens do admin para as lojas,
 * avisos do teste grátis). Daqui dá para criar a sessão na WAHA e ler o QR Code, desconectar o aparelho e apagar
 * a sessão, sem mexer no .env nem no painel da WAHA.
 *
 * O nome da sessão criada aqui fica em PlatformSetting; sem ele, vale PLATFORM_WAHA_SESSION do .env.
 */
class PlatformWhatsAppController extends Controller
{
    /** Nome usado quando não há sessão nenhuma (nem no painel nem no .env). */
    public const DEFAULT_SESSION = 'meu-custo-total-suporte';

    public function __construct(private WahaClient $waha) {}

    /** GET /admin/system/platform-whatsapp — situação; a tela consulta de novo enquanto espera o QR ser lido. */
    public function show()
    {
        return response()->json($this->payload());
    }

    /** POST /admin/system/platform-whatsapp/connect — cria a sessão (ou reinicia) e devolve o QR Code. */
    public function connect(Request $request)
    {
        abort_unless($this->waha->isConfigured(), 422, 'A WAHA não está configurada no servidor (WAHA_URL e WAHA_API_KEY).');
        $data = $request->validate([
            'session' => ['nullable', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
        ], [
            'session.regex' => 'Use só letras minúsculas, números, hífen e sublinhado no nome da sessão.',
        ]);

        // Já existe uma (painel ou .env): reconecta essa. Senão cria com o nome pedido (ou o padrão).
        $name = PlatformWhatsApp::sessionName() ?: ($data['session'] ?? null) ?: self::DEFAULT_SESSION;
        abort_if(
            Company::where('whatsapp_session_name', $name)->exists(),
            422,
            "O nome \"{$name}\" já é a sessão do WhatsApp de uma loja. Escolha outro."
        );

        try {
            $this->waha->ensureSessionStarted($name);
        } catch (Throwable $e) {
            abort(422, 'A WAHA não iniciou a sessão: '.Str::limit($e->getMessage(), 200));
        }
        if (PlatformWhatsApp::sessionSource() === null) {
            PlatformSetting::put(PlatformSetting::PLATFORM_WAHA_SESSION, $name);
        }
        AdminAudit::log($request, 'whatsapp.platform_connect', null, null, ['session' => $name]);

        return response()->json($this->payload());
    }

    /** POST /admin/system/platform-whatsapp/disconnect — desloga o aparelho; conectar de novo pede outro QR Code. */
    public function disconnect(Request $request)
    {
        $name = $this->requireSession();
        try {
            $this->waha->logoutSession($name);
        } catch (Throwable $e) {
            abort(422, 'A WAHA não desconectou a sessão: '.Str::limit($e->getMessage(), 200));
        }
        AdminAudit::log($request, 'whatsapp.platform_disconnect', null, null, ['session' => $name]);

        return response()->json($this->payload());
    }

    /**
     * DELETE /admin/system/platform-whatsapp — apaga a sessão na WAHA. Se ela foi criada pelo painel, o nome também
     * sai; se vem do .env, o nome continua lá e "Conectar" cria de novo com ele.
     */
    public function destroy(Request $request)
    {
        $name = $this->requireSession();
        try {
            $this->waha->deleteSession($name);
        } catch (Throwable $e) {
            abort(422, 'A WAHA não apagou a sessão: '.Str::limit($e->getMessage(), 200));
        }
        if (PlatformWhatsApp::sessionSource() === 'panel') {
            PlatformSetting::put(PlatformSetting::PLATFORM_WAHA_SESSION, null);
        }
        AdminAudit::log($request, 'whatsapp.platform_delete', null, null, ['session' => $name]);

        return response()->json($this->payload());
    }

    private function requireSession(): string
    {
        abort_unless($this->waha->isConfigured(), 422, 'A WAHA não está configurada no servidor (WAHA_URL e WAHA_API_KEY).');
        $name = PlatformWhatsApp::sessionName();
        abort_unless($name, 422, 'Nenhuma sessão do WhatsApp da plataforma para mexer.');

        return $name;
    }

    /**
     * status: "unavailable" (sem WAHA), "not_created" (sem sessão na WAHA), "not_connected" (desconectada por aqui)
     * ou a situação da WAHA (STARTING, SCAN_QR_CODE, WORKING, FAILED).
     */
    private function payload(): array
    {
        $name = PlatformWhatsApp::sessionName();
        $base = [
            'session' => $name,
            'source' => PlatformWhatsApp::sessionSource(),
            'default_session' => self::DEFAULT_SESSION,
            'phone' => null,
            'qr' => null,
            'problem' => null,
            // Quem recebe os avisos de chamado novo (PLATFORM_SUPPORT_WHATSAPP)
            'team_numbers' => count(config('services.platform.support_whatsapp', [])),
        ];

        if (! $this->waha->isConfigured()) {
            return ['status' => 'unavailable'] + $base;
        }
        if (! $name) {
            return ['status' => 'not_created'] + $base;
        }

        try {
            $info = $this->waha->getSession($name);
        } catch (Throwable $e) {
            return ['status' => 'error', 'problem' => 'A WAHA não respondeu: '.Str::limit($e->getMessage(), 150)] + $base;
        }
        if (! $info) {
            return ['status' => 'not_created'] + $base;
        }

        // STOPPED é o "desconectado por aqui" (ver WahaClient::logoutSession)
        $status = ($info['status'] ?? null) === 'STOPPED' ? 'not_connected' : (string) ($info['status'] ?? 'unknown');
        $phone = $info['me']['id'] ?? null;

        return [
            'status' => $status,
            'phone' => $phone ? preg_replace('/@.*$/', '', (string) $phone) : null,
            'push_name' => $info['me']['pushName'] ?? null,
            'qr' => $status === 'SCAN_QR_CODE' ? $this->waha->getQrCodeDataUri($name) : null,
            'problem' => in_array($status, ['WORKING', 'not_connected', 'SCAN_QR_CODE', 'STARTING'], true) ? null : WahaClient::problem($info),
        ] + $base;
    }
}
