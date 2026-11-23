<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Admin → Sistema → Recursos: esconder o Mercado Pago e o PagBank dos lojistas, cada um separado. */
class GatewayVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $shop;

    private string $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.pagbank.client_id' => null, 'services.mercadopago.test_access_token' => null]);
        $this->company = Company::create(['name' => 'Loja', 'slug' => 'loja-gw', 'plan' => Company::PLAN_PRO]);
        $owner = User::factory()->create(['company_id' => $this->company->id]);
        $admin = User::factory()->create(['company_id' => $this->company->id]);
        config(['services.platform.admin_emails' => [strtolower($admin->email)]]);
        $this->shop = $owner->createToken('api')->plainTextToken;
        $this->admin = $admin->createToken('api')->plainTextToken;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_each_gateway_is_hidden_separately(): void
    {
        $this->as($this->admin)->getJson('/api/v1/admin/features')->assertOk()
            ->assertJsonPath('mercadopago_visible', true)->assertJsonPath('pagbank_visible', true);
        $this->as($this->shop)->patchJson('/api/v1/admin/features', ['pagbank_visible' => false])->assertNotFound();

        $this->as($this->admin)->patchJson('/api/v1/admin/features', ['pagbank_visible' => false])->assertOk()
            ->assertJsonPath('pagbank_visible', false)->assertJsonPath('mercadopago_visible', true)->assertJsonPath('alexa_visible', false);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'features.pagbank']);

        $this->as($this->shop)->getJson('/api/v1/payments/pagbank')->assertJsonPath('visible', false);
        $this->as($this->shop)->getJson('/api/v1/payments/mercadopago')->assertJsonPath('visible', true);
        $this->as($this->shop)->postJson('/api/v1/payments/pagbank/token', ['token' => 'tok-0123456789abcdefghij'])
            ->assertForbidden()->assertJsonPath('message', 'O pagamento pelo PagBank não está disponível no momento.');
        $this->as($this->shop)->postJson('/api/v1/payments/pagbank/connect')->assertForbidden();
        // O admin da plataforma sempre vê (para testar)
        $this->as($this->admin)->getJson('/api/v1/payments/pagbank')->assertJsonPath('visible', true);

        $this->as($this->admin)->patchJson('/api/v1/admin/features', ['mercadopago_visible' => false, 'pagbank_visible' => true])->assertOk();
        $this->as($this->shop)->getJson('/api/v1/payments/mercadopago')->assertJsonPath('visible', false);
        $this->as($this->shop)->postJson('/api/v1/payments/mercadopago/connect')->assertForbidden();
        $this->as($this->shop)->getJson('/api/v1/payments/pagbank')->assertJsonPath('visible', true);
    }

    public function test_store_already_connected_keeps_seeing_the_hidden_gateway(): void
    {
        PaymentAccount::create(['company_id' => $this->company->id, 'provider' => 'mercadopago', 'provider_user_id' => '1', 'access_token' => 'x', 'connected_at' => now()]);
        $this->as($this->admin)->patchJson('/api/v1/admin/features', ['mercadopago_visible' => false])->assertOk();

        $this->as($this->shop)->getJson('/api/v1/payments/mercadopago')->assertJsonPath('visible', false)->assertJsonPath('connected', true);
        $this->as($this->shop)->deleteJson('/api/v1/payments/mercadopago')->assertOk();
    }

    public function test_empty_update_is_refused(): void
    {
        $this->as($this->admin)->patchJson('/api/v1/admin/features', [])->assertStatus(422);
    }
}
