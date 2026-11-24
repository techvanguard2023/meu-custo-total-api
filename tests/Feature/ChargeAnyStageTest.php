<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cobrar o cliente em qualquer etapa do pedido, desde que haja valor em aberto (tudo ou parte). */
class ChargeAnyStageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Loja', 'slug' => 'loja-cobranca', 'plan' => Company::PLAN_PRO, 'whatsapp_pix_key' => 'pix@loja.com']);
        $this->token = User::factory()->create(['company_id' => $this->company->id])->createToken('api')->plainTextToken;
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Michelle Souza', 'phone' => '21988887777']);
    }

    private function order(string $stage, float $paid = 0, array $extra = []): Quote
    {
        return Quote::forceCreate($extra + [
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'name' => 'Pedido', 'status' => Quote::STATUS_APPROVED,
            'production_status' => $stage, 'quantity' => 1, 'final_price' => 100, 'amount_paid' => $paid,
            'print_time_minutes' => 0, 'material_weight_g' => 0, 'approved_at' => now(),
        ]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    public function test_orders_in_production_and_ready_can_be_charged_together(): void
    {
        $inProduction = $this->order(Quote::PRODUCTION_IN_PRODUCTION);
        $ready = $this->order(Quote::PRODUCTION_FINISHED, 40);

        $this->api()->getJson("/api/v1/quotes/{$inProduction->id}/charge")->assertOk()->assertJsonCount(2, 'open');

        $this->api()->postJson('/api/v1/quotes/charge', ['quote_ids' => [$inProduction->id, $ready->id]])
            ->assertOk()->assertJsonPath('total', 160);
        $this->assertNotNull($inProduction->fresh()->charge_requested_at);
    }

    public function test_charge_points_to_the_orders_portal_when_the_catalog_is_live(): void
    {
        $order = $this->order(Quote::PRODUCTION_FINISHED);

        // Sem catálogo: vai a chave Pix cadastrada
        $message = $this->api()->postJson('/api/v1/quotes/charge', ['quote_ids' => [$order->id]])->assertOk()->json('message');
        $this->assertStringContainsString('pix@loja.com', $message);
        $this->assertStringNotContainsString('meus-pedidos', $message);

        // Catálogo no ar: leva para "Meus pedidos", onde o cliente vê as formas de pagamento
        $this->company->update(['catalog_enabled' => true, 'catalog_token' => 'tok-catalogo']);
        $portal = $this->company->fresh()->ordersPortalUrl();

        $this->api()->getJson("/api/v1/quotes/{$order->id}/charge")->assertOk()->assertJsonPath('payment.portal_url', $portal);
        $message = $this->api()->postJson('/api/v1/quotes/charge', ['quote_ids' => [$order->id]])->assertOk()->json('message');
        $this->assertStringContainsString($portal, $message);
        $this->assertStringNotContainsString('pix@loja.com', $message);
    }

    public function test_paid_cancelled_or_courtesy_orders_are_not_charged(): void
    {
        $paid = $this->order(Quote::PRODUCTION_PENDING, 100);
        $cancelled = $this->order(Quote::PRODUCTION_PENDING, 0, ['cancelled_at' => now()]);
        $courtesy = $this->order(Quote::PRODUCTION_PENDING, 0, ['is_courtesy' => true]);

        foreach ([$paid, $cancelled, $courtesy] as $quote) {
            $this->api()->getJson("/api/v1/quotes/{$quote->id}/charge")->assertStatus(422);
        }
    }
}
