<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\PlatformWhatsApp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * WhatsApp da plataforma (mensagem do admin para a loja): confere a sessão antes de enviar e explica em português
 * quando não deu — antes, com a sessão caída, a ficha mostrava o erro do cURL depois de 8s esperando.
 */
class PlatformWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.waha.url' => 'https://waha.teste',
            'services.waha.api_key' => 'chave',
            'services.platform.support_waha_session' => 'plataforma',
            'services.platform.admin_emails' => ['admin@plataforma.test'],
        ]);
        Cache::flush();
        $this->company = Company::create(['name' => 'Loja Zap', 'slug' => 'loja-zap-plat', 'plan' => Company::PLAN_PRO]);
        User::factory()->create(['company_id' => $this->company->id, 'role' => User::ROLE_OWNER, 'name' => 'Dona Ana', 'phone' => '21999990000']);
    }

    public function test_session_down_is_reported_without_trying_to_send(): void
    {
        Http::fake(['waha.teste/api/sessions/plataforma' => Http::response(['name' => 'plataforma', 'status' => 'FAILED', 'me' => ['id' => '5521@c.us']])]);

        $result = app(PlatformWhatsApp::class)->sendToOwner($this->company, 'Olá');

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('o WhatsApp da plataforma está fora do ar (FAILED', $result['status']);
        $this->assertStringContainsString('reiniciar a sessão costuma resolver', $result['status']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sendText'));
    }

    public function test_timeout_says_the_message_may_have_arrived(): void
    {
        Http::fake([
            'waha.teste/api/sessions/plataforma' => Http::response(['status' => 'WORKING', 'me' => ['id' => 'x']]),
            'waha.teste/api/contacts/check-exists*' => Http::response(['numberExists' => true, 'chatId' => '5521999990000@c.us']),
            'waha.teste/api/sendText' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);

        $result = app(PlatformWhatsApp::class)->sendToOwner($this->company, 'Olá');

        $this->assertFalse($result['sent']);
        $this->assertStringStartsWith('Sem confirmação:', $result['status']);
        $this->assertStringNotContainsString('cURL', $result['status']);
    }

    public function test_sends_when_the_session_is_working_and_only_previews_links(): void
    {
        Http::fake([
            'waha.teste/api/sessions/plataforma' => Http::response(['status' => 'WORKING', 'me' => ['id' => 'x']]),
            'waha.teste/api/contacts/check-exists*' => Http::response(['numberExists' => true, 'chatId' => '5521999990000@c.us']),
            'waha.teste/api/sendText' => Http::response(['id' => 'msg']),
        ]);

        $result = app(PlatformWhatsApp::class)->sendToOwner($this->company, 'Sem link aqui');

        $this->assertTrue($result['sent']);
        $this->assertSame('Enviado para Dona Ana (21999990000)', $result['status']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendText') && ! isset($request['linkPreview']));
    }

    public function test_admin_system_shows_the_platform_session_and_restarts_it(): void
    {
        Http::fake([
            'waha.teste/api/sessions?*' => Http::response([['name' => 'plataforma', 'status' => 'FAILED', 'me' => ['id' => 'x']]]),
            'waha.teste/api/sessions/plataforma/restart' => Http::response(['name' => 'plataforma', 'status' => 'STARTING']),
            'waha.teste/api/sessions/plataforma' => Http::response(['name' => 'plataforma', 'status' => 'STARTING']),
        ]);
        $admin = User::factory()->create(['company_id' => $this->company->id, 'email' => 'admin@plataforma.test']);
        $token = $admin->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/system')->assertOk()
            ->assertJsonPath('whatsapp.platform.session', 'plataforma')
            ->assertJsonPath('whatsapp.platform.status', 'FAILED');

        $this->withToken($token)->postJson('/api/v1/admin/system/platform-whatsapp/restart')->assertOk()->assertJsonPath('status', 'STARTING');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/sessions/plataforma/restart'));

        $owner = User::where('role', User::ROLE_OWNER)->first()->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();
        // A área admin nem aparece para quem não é da plataforma
        $this->withToken($owner)->postJson('/api/v1/admin/system/platform-whatsapp/restart')->assertNotFound();
    }
}
