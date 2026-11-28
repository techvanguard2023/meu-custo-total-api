<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PlatformWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Admin → Sistema: criar a sessão do WhatsApp da plataforma (QR Code), desconectar e apagar pelo painel. */
class PlatformWhatsAppPanelTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    /** Situação fingida da sessão na WAHA: null = não existe */
    private ?string $wahaStatus = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.waha.url' => 'https://waha.teste',
            'services.waha.api_key' => 'chave',
            'services.platform.support_waha_session' => null,
            'services.platform.admin_emails' => ['admin@plataforma.test'],
        ]);
        Cache::flush();
        $this->company = Company::create(['name' => 'Loja', 'slug' => 'loja-painel-zap', 'plan' => Company::PLAN_PRO, 'whatsapp_session_name' => 'loja-painel-zap']);
        $admin = User::factory()->create(['company_id' => $this->company->id, 'email' => 'admin@plataforma.test']);
        $this->token = $admin->createToken('api')->plainTextToken;

        // WAHA fingida: guarda a situação da sessão entre as chamadas
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();

            if ($method === 'POST' && $path === '/api/sessions') {
                $this->wahaStatus = 'SCAN_QR_CODE';

                return Http::response(['name' => $request['name'], 'status' => 'STARTING'], 201);
            }
            if (preg_match('#^/api/sessions/([^/]+)$#', $path, $m)) {
                if ($method === 'DELETE') {
                    $this->wahaStatus = null;

                    return Http::response(null, 200);
                }
                if ($method === 'PUT') {
                    return Http::response([], 200);
                }

                return $this->wahaStatus === null
                    ? Http::response(['message' => 'not found'], 404)
                    : Http::response(['name' => $m[1], 'status' => $this->wahaStatus, 'config' => [], 'me' => $this->wahaStatus === 'WORKING' ? ['id' => '5521977776666@c.us', 'pushName' => 'Suporte'] : null]);
            }
            if (preg_match('#/(start|restart)$#', $path)) {
                $this->wahaStatus = 'SCAN_QR_CODE';

                return Http::response(['status' => 'STARTING']);
            }
            if (str_ends_with($path, '/logout')) {
                return Http::response([]);
            }
            if (str_ends_with($path, '/stop')) {
                $this->wahaStatus = 'STOPPED';

                return Http::response([]);
            }
            if (str_ends_with($path, '/auth/qr')) {
                return Http::response(['mimetype' => 'image/png', 'data' => 'QUJD']);
            }

            return Http::response([], 404);
        });
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    public function test_creates_connects_disconnects_and_deletes_the_session_from_the_panel(): void
    {
        $this->api()->getJson('/api/v1/admin/system/platform-whatsapp')->assertOk()
            ->assertJsonPath('status', 'not_created')->assertJsonPath('session', null);

        // Criar: a WAHA cria a sessão e devolve o QR Code para ler no celular
        $this->api()->postJson('/api/v1/admin/system/platform-whatsapp/connect', ['session' => 'suporte-mct'])->assertOk()
            ->assertJsonPath('status', 'SCAN_QR_CODE')
            ->assertJsonPath('session', 'suporte-mct')
            ->assertJsonPath('source', 'panel')
            ->assertJsonPath('qr', 'data:image/png;base64,QUJD');
        $this->assertSame('suporte-mct', PlatformWhatsApp::sessionName());
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/sessions') && $r['name'] === 'suporte-mct');

        // Leu o QR: conectado, com o número
        $this->wahaStatus = 'WORKING';
        $this->api()->getJson('/api/v1/admin/system/platform-whatsapp')->assertOk()
            ->assertJsonPath('status', 'WORKING')->assertJsonPath('phone', '5521977776666')->assertJsonPath('qr', null);

        // Desconectar: desloga o aparelho, a sessão continua
        $this->api()->postJson('/api/v1/admin/system/platform-whatsapp/disconnect')->assertOk()->assertJsonPath('status', 'not_connected');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sessions/suporte-mct/logout'));

        // Conectar de novo: reinicia a mesma sessão e pede outro QR
        $this->api()->postJson('/api/v1/admin/system/platform-whatsapp/connect')->assertOk()->assertJsonPath('status', 'SCAN_QR_CODE');

        // Apagar: some da WAHA e o nome sai do painel
        $this->api()->deleteJson('/api/v1/admin/system/platform-whatsapp')->assertOk()
            ->assertJsonPath('status', 'not_created')->assertJsonPath('session', null);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/sessions/suporte-mct'));
        $this->assertNull(PlatformSetting::get(PlatformSetting::PLATFORM_WAHA_SESSION));

        $audit = \DB::table('admin_audit_logs')->pluck('action')->all();
        $this->assertSame(['whatsapp.platform_connect', 'whatsapp.platform_disconnect', 'whatsapp.platform_connect', 'whatsapp.platform_delete'], $audit);
    }

    public function test_env_session_is_kept_and_store_session_names_are_refused(): void
    {
        // Nome de sessão de uma loja: recusado
        $this->api()->postJson('/api/v1/admin/system/platform-whatsapp/connect', ['session' => 'loja-painel-zap'])->assertStatus(422);
        $this->api()->postJson('/api/v1/admin/system/platform-whatsapp/connect', ['session' => 'Com Espaço'])->assertStatus(422);

        // Sessão do .env: apagar tira da WAHA, mas o nome continua valendo (o .env não muda pelo painel)
        config(['services.platform.support_waha_session' => 'plataforma']);
        $this->wahaStatus = 'FAILED';
        $this->api()->getJson('/api/v1/admin/system/platform-whatsapp')->assertOk()
            ->assertJsonPath('session', 'plataforma')->assertJsonPath('source', 'env')->assertJsonPath('status', 'FAILED');
        $this->api()->deleteJson('/api/v1/admin/system/platform-whatsapp')->assertOk()
            ->assertJsonPath('status', 'not_created')->assertJsonPath('session', 'plataforma');
        $this->api()->postJson('/api/v1/admin/system/platform-whatsapp/connect')->assertOk()
            ->assertJsonPath('session', 'plataforma')->assertJsonPath('status', 'SCAN_QR_CODE');
        $this->assertNull(PlatformSetting::get(PlatformSetting::PLATFORM_WAHA_SESSION));

        // Quem não é da plataforma nem vê a rota
        $owner = User::factory()->create(['company_id' => $this->company->id])->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($owner)->getJson('/api/v1/admin/system/platform-whatsapp')->assertNotFound();
    }
}
