<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bot do WhatsApp com um fluxo só para todas as lojas: cada chamada fica com a loja da sessão, nunca com outra. */
class BotSessionTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE = 'chave-do-servico-do-bot';

    private User $alma;

    private User $jup;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bot.service_token' => self::SERVICE]);
        $this->alma = $this->store('Alma', 'alma-forma-3d', 'pix-da-alma@exemplo.com');
        $this->jup = $this->store('Jup', 'jup-DIPUoz', 'pix-da-jup@exemplo.com');
    }

    private function store(string $name, string $session, string $pix): User
    {
        $company = Company::create(['name' => $name, 'slug' => $session, 'plan' => Company::PLAN_PRO, 'whatsapp_session_name' => $session, 'whatsapp_pix_key' => $pix]);

        return User::factory()->create(['company_id' => $company->id, 'role' => User::ROLE_OWNER]);
    }

    private function bot(string $session, string $token = self::SERVICE)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Bot-Session' => $session]);
    }

    public function test_service_key_gets_the_settings_of_the_session_store(): void
    {
        $this->bot('jup-DIPUoz')->getJson('/api/v1/whatsapp/settings')->assertOk()->assertJsonPath('pix_key', 'pix-da-jup@exemplo.com');
        $this->bot('alma-forma-3d')->getJson('/api/v1/whatsapp/settings')->assertOk()->assertJsonPath('pix_key', 'pix-da-alma@exemplo.com');
    }

    public function test_order_goes_to_the_session_store(): void
    {
        $this->bot('jup-DIPUoz')->postJson('/api/v1/whatsapp/handoffs', ['phone' => '5521991432972', 'reason' => 'Comprovante'])->assertSuccessful();

        $this->assertDatabaseHas('whatsapp_handoffs', ['company_id' => $this->jup->company_id]);
        $this->assertDatabaseMissing('whatsapp_handoffs', ['company_id' => $this->alma->company_id]);
    }

    public function test_service_key_only_opens_bot_routes(): void
    {
        $this->bot('jup-DIPUoz')->getJson('/api/v1/products')->assertForbidden();
    }

    public function test_unknown_session_or_wrong_key_is_refused(): void
    {
        $this->bot('loja-que-nao-existe')->getJson('/api/v1/whatsapp/settings')->assertNotFound();
        $this->bot('jup-DIPUoz', 'chave-errada')->getJson('/api/v1/whatsapp/settings')->assertUnauthorized();
    }

    public function test_token_of_another_store_is_refused_for_a_session(): void
    {
        $almaToken = $this->alma->createToken('whatsapp-bot')->plainTextToken;

        $this->bot('jup-DIPUoz', $almaToken)->getJson('/api/v1/whatsapp/settings')
            ->assertForbidden()
            ->assertJsonPath('message', 'Este token é de outra loja: a conversa é da sessão "jup-DIPUoz". Use a chave do serviço do bot.');
        // O token da própria loja segue valendo
        $this->bot('alma-forma-3d', $almaToken)->getJson('/api/v1/whatsapp/settings')->assertOk()->assertJsonPath('pix_key', 'pix-da-alma@exemplo.com');
    }

    public function test_without_service_key_configured_nothing_changes(): void
    {
        config(['services.bot.service_token' => null]);
        $this->bot('jup-DIPUoz', '')->getJson('/api/v1/whatsapp/settings')->assertUnauthorized();
    }
}
