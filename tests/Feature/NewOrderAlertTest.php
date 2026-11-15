<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Aviso de pedido novo: o que chega pelo bot do WhatsApp acende "Produção" no menu até o lojista abrir a tela. */
class NewOrderAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $owner;

    private string $bot;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Loja', 'slug' => 'loja-aviso', 'plan' => Company::PLAN_PRO]);
        $this->user = User::factory()->create(['company_id' => $company->id]);
        $this->owner = $this->user->createToken('api')->plainTextToken;
        $this->productId = $this->withToken($this->owner)
            ->postJson('/api/v1/products', ['name' => 'Chaveiro', 'cost' => 5, 'sale_price' => 15, 'stock_quantity' => 2])
            ->assertCreated()->json('id');
        $this->bot = $this->user->createToken('whatsapp-bot')->plainTextToken;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function botOrder(int $quantity, string $reference = 'msg-1')
    {
        return $this->as($this->bot)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Ana', 'customer_phone' => '5521999990000', 'external_reference' => $reference,
            'products' => [['product_id' => $this->productId, 'quantity' => $quantity]],
        ]);
    }

    private function newCount(): int
    {
        return $this->as($this->owner)->getJson('/api/v1/quotes/new-orders')->assertOk()->json('count');
    }

    public function test_bot_order_from_stock_and_backorder_both_count_as_new(): void
    {
        // Um item em estoque + um sob encomenda: vira um pedido Finalizado e outro Pendente
        $toOrder = $this->as($this->owner)->postJson('/api/v1/products', [
            'name' => 'Luminária', 'cost' => 20, 'sale_price' => 60, 'stock_quantity' => 0, 'made_to_order' => true, 'lead_time_days' => 5,
        ])->assertCreated()->json('id');
        $quotes = $this->as($this->bot)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Ana', 'customer_phone' => '5521999990000', 'external_reference' => 'msg-1',
            'products' => [['product_id' => $this->productId, 'quantity' => 1], ['product_id' => $toOrder, 'quantity' => 1]],
        ])->assertCreated()->json();

        $this->assertCount(2, $quotes);
        $this->assertEqualsCanonicalizing(['finished', 'pending'], array_column($quotes, 'production_status'));
        $this->assertSame([true, true], array_column($quotes, 'is_new_order'));
        $this->assertSame(2, $this->newCount());

        // A Produção mostra o selo "Novo" nos cards
        $board = $this->as($this->owner)->getJson('/api/v1/quotes?scope=production')->assertOk()->json('data');
        $this->assertSame([true, true], array_column($board, 'is_new_order'));
    }

    public function test_opening_production_marks_new_orders_as_seen(): void
    {
        $this->botOrder(1)->assertCreated();
        $this->assertSame(1, $this->newCount());

        $this->as($this->owner)->postJson('/api/v1/quotes/new-orders/seen')->assertOk()->assertJsonPath('count', 0);

        $this->assertSame(0, $this->newCount());
        $this->assertDatabaseMissing('quotes', ['is_new_order' => true]);
    }

    public function test_counter_sale_is_not_new(): void
    {
        $this->as($this->owner)->postJson('/api/v1/quotes/quick-sale', [
            'payment_method' => 'pix',
            'products' => [['product_id' => $this->productId, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame(0, $this->newCount());
    }

    public function test_impersonating_admin_does_not_clear_the_alert(): void
    {
        $this->botOrder(1)->assertCreated();
        $admin = User::factory()->create(['company_id' => $this->user->company_id]);
        $impersonation = $this->user->createToken('impersonation:'.$admin->id)->plainTextToken;

        $this->as($impersonation)->postJson('/api/v1/quotes/new-orders/seen')->assertOk()->assertJsonPath('count', 1);

        $this->assertSame(1, $this->newCount());
    }

    public function test_bot_token_cannot_read_or_clear_the_alert(): void
    {
        $this->as($this->bot)->getJson('/api/v1/quotes/new-orders')->assertForbidden();
        $this->as($this->bot)->postJson('/api/v1/quotes/new-orders/seen')->assertForbidden();
    }
}
