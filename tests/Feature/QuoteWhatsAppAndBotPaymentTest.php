<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Botão "Enviar no WhatsApp" do orçamento (sem custos), opção do pagamento do pedido do bot (conversa ou
 * "Meus pedidos") e o aviso de pedido novo liberado para quem é da produção.
 */
class QuoteWhatsAppAndBotPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create([
            'name' => 'Loja Zap', 'slug' => 'loja-zap', 'plan' => Company::PLAN_PRO, 'catalog_enabled' => true, 'catalog_token' => 'token-zap',
            'whatsapp_pix_key' => 'chave@loja.com', 'whatsapp_payment_link' => 'https://mpago.la/loja',
        ]);
        $this->owner = User::factory()->create(['company_id' => $this->company->id]);
        $this->token = $this->owner->createToken('api')->plainTextToken;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function quote(?string $phone = '21999990000', string $status = Quote::STATUS_SENT): Quote
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Ana Lima', 'phone' => $phone]);

        return Quote::forceCreate([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'name' => 'Topo de bolo', 'status' => $status,
            'quantity' => 2, 'final_price' => 90, 'subtotal_cost' => 31.4, 'material_cost' => 12.5, 'profit_amount' => 58.6,
            'print_time_minutes' => 0, 'material_weight_g' => 0, 'delivery_days' => 5,
        ]);
    }

    public function test_send_without_connected_whatsapp_returns_summary_without_costs(): void
    {
        $quote = $this->quote();

        $res = $this->as($this->token)->postJson("/api/v1/quotes/{$quote->id}/whatsapp")->assertOk()
            ->assertJsonPath('sent', false)->assertJsonPath('reason', 'not_connected')->assertJsonPath('phone', '5521999990000');

        $text = $res->json('text');
        $this->assertStringContainsString("orçamento *#{$quote->id}*", $text);
        $this->assertStringContainsString('2x Topo de bolo — R$ 90,00', $text);
        $this->assertStringContainsString('*Total: R$ 90,00*', $text);
        $this->assertStringContainsString('Prazo: 5 dias', $text);
        // Nada de custo, lucro nem margem
        $this->assertStringNotContainsString('31,40', $text);
        $this->assertStringNotContainsString('12,50', $text);
        $this->assertStringNotContainsString('58,60', $text);
        $this->assertStringNotContainsStringIgnoringCase('custo', $text);
        $this->assertStringNotContainsStringIgnoringCase('lucro', $text);
    }

    public function test_send_reports_missing_phone_and_refuses_cancelled_and_other_stores(): void
    {
        $this->as($this->token)->postJson('/api/v1/quotes/'.$this->quote(null)->id.'/whatsapp')
            ->assertOk()->assertJsonPath('reason', 'no_phone')->assertJsonPath('phone', null);

        $cancelled = $this->quote();
        $cancelled->forceFill(['status' => Quote::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
        $this->as($this->token)->postJson("/api/v1/quotes/{$cancelled->id}/whatsapp")->assertStatus(422);

        $other = Company::create(['name' => 'Outra', 'slug' => 'outra-zap', 'plan' => Company::PLAN_PRO]);
        $stranger = User::factory()->create(['company_id' => $other->id])->createToken('api')->plainTextToken;
        $this->as($stranger)->postJson('/api/v1/quotes/'.$this->quote()->id.'/whatsapp')->assertForbidden();
    }

    public function test_production_team_cannot_send_quotes_but_sees_new_orders(): void
    {
        $production = User::factory()->create(['company_id' => $this->company->id, 'role' => User::ROLE_PRODUCTION])->createToken('api')->plainTextToken;

        $this->as($production)->postJson('/api/v1/quotes/'.$this->quote()->id.'/whatsapp')->assertForbidden();
        $this->as($production)->getJson('/api/v1/quotes/new-orders')->assertOk()->assertJsonPath('count', 0);
        $this->as($production)->postJson('/api/v1/quotes/new-orders/seen')->assertOk();
    }

    public function test_bot_payment_setting_defaults_to_whatsapp_and_portal_needs_the_catalog(): void
    {
        $this->as($this->token)->getJson('/api/v1/whatsapp/settings')->assertOk()
            ->assertJsonPath('bot_payment', 'whatsapp')->assertJsonPath('orders_portal_url', null);

        $this->as($this->token)->putJson('/api/v1/whatsapp/settings', ['bot_payment' => 'portal', 'pix_key' => 'chave@loja.com'])->assertOk()
            ->assertJsonPath('bot_payment', 'portal')->assertJsonPath('bot_payment_choice', 'portal')
            ->assertJsonPath('orders_portal_url', 'http://localhost:3000/catalog/loja-zap/meus-pedidos');

        // Salvar sem mandar a opção (tela antiga) não muda o que foi escolhido
        $this->as($this->token)->putJson('/api/v1/whatsapp/settings', ['pix_key' => 'chave@loja.com'])->assertOk()->assertJsonPath('bot_payment', 'portal');

        $this->company->update(['catalog_enabled' => false]);
        $this->as($this->token)->putJson('/api/v1/whatsapp/settings', ['bot_payment' => 'portal'])->assertStatus(422);
        // Catálogo desligado depois: volta a cobrar pelo WhatsApp
        $this->as($this->token)->getJson('/api/v1/whatsapp/settings')->assertJsonPath('bot_payment', 'whatsapp')->assertJsonPath('bot_payment_choice', 'portal');
    }

    public function test_portal_mode_sends_the_orders_link_instead_of_payment_data(): void
    {
        $this->company->update(['whatsapp_bot_payment' => 'portal']);
        $productId = $this->as($this->token)->postJson('/api/v1/products', ['name' => 'Chaveiro', 'cost' => 5, 'sale_price' => 15, 'stock_quantity' => 5])
            ->assertCreated()->json('id');
        $bot = $this->owner->createToken('whatsapp-bot')->plainTextToken;

        // O bot não recebe a chave Pix nem o link (a tela da loja continua vendo)
        $this->as($bot)->getJson('/api/v1/whatsapp/settings')->assertOk()
            ->assertJsonPath('bot_payment', 'portal')->assertJsonPath('pix_key', null)->assertJsonPath('payment_link', null);
        $this->as($this->token)->getJson('/api/v1/whatsapp/settings')->assertJsonPath('pix_key', 'chave@loja.com');

        $order = $this->as($bot)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Ana', 'customer_phone' => '5521999990000', 'external_reference' => 'msg-portal',
            'products' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertCreated();

        $order->assertJsonPath('0.payment.mode', 'portal')
            ->assertJsonPath('0.payment.url', 'http://localhost:3000/catalog/loja-zap/meus-pedidos');
        $this->assertStringContainsString('Meus pedidos', $order->json('0.payment.message'));

        $this->as($bot)->getJson('/api/v1/whatsapp/order-payment?reference=msg-portal')->assertOk()->assertJsonPath('messages', []);
    }

    public function test_whatsapp_mode_keeps_the_previous_behavior(): void
    {
        $productId = $this->as($this->token)->postJson('/api/v1/products', ['name' => 'Chaveiro', 'cost' => 5, 'sale_price' => 15, 'stock_quantity' => 5])
            ->assertCreated()->json('id');
        $bot = $this->owner->createToken('whatsapp-bot')->plainTextToken;

        $this->as($bot)->getJson('/api/v1/whatsapp/settings')->assertJsonPath('pix_key', 'chave@loja.com');
        $order = $this->as($bot)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Ana', 'customer_phone' => '5521999990000', 'products' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertCreated();

        // Sem Mercado Pago conectado não há cobrança online: nada de "payment" (o bot usa a chave e o link da loja)
        $this->assertNull($order->json('0.payment'));
    }
}
