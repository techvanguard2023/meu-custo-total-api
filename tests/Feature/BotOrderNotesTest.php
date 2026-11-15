<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bot do WhatsApp: observação do cliente no pedido (na hora de criar e depois de criado). */
class BotOrderNotesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $bot;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Loja', 'slug' => 'loja-notas', 'plan' => Company::PLAN_PRO]);
        $this->user = User::factory()->create(['company_id' => $company->id]);
        $this->productId = $this->withToken($this->user->createToken('api')->plainTextToken)
            ->postJson('/api/v1/products', ['name' => 'Enfeite de Natal personalizado', 'cost' => 5, 'sale_price' => 15, 'stock_quantity' => 10])
            ->assertCreated()->json('id');
        $this->app['auth']->forgetGuards();
        $this->bot = $this->user->createToken('whatsapp-bot')->plainTextToken;
    }

    private function order(array $extra = [], string $reference = 'msg-1')
    {
        return $this->withToken($this->bot)->postJson('/api/v1/quotes/external-order', $extra + [
            'customer_name' => 'Ana', 'customer_phone' => '5521999990000', 'external_reference' => $reference,
            'products' => [['product_id' => $this->productId, 'quantity' => 2]],
        ]);
    }

    public function test_order_keeps_the_personalization_note(): void
    {
        $this->order(['notes' => 'Enfeite de Natal personalizado: nome Maria'])->assertCreated();

        $this->assertDatabaseHas('quotes', ['external_reference' => 'msg-1', 'notes' => 'Enfeite de Natal personalizado: nome Maria']);
    }

    public function test_note_after_the_order_is_appended_once(): void
    {
        $this->order(['notes' => 'Nome: Maria'])->assertCreated();

        $note = fn (string $text) => $this->withToken($this->bot)->postJson('/api/v1/whatsapp/order-notes', ['phone' => '(21) 99999-0000', 'note' => $text]);
        $note('Embalagem para presente')->assertOk()->assertJsonPath('orders.0.notes', "Nome: Maria\nEmbalagem para presente");
        $note('Embalagem para presente')->assertOk()->assertJsonPath('orders.0.notes', "Nome: Maria\nEmbalagem para presente");
    }

    public function test_note_goes_to_the_latest_order_only(): void
    {
        $this->order([], 'msg-1')->assertCreated();
        $this->order([], 'msg-2')->assertCreated();

        $this->withToken($this->bot)->postJson('/api/v1/whatsapp/order-notes', ['phone' => '5521999990000', 'note' => 'Nome: João'])->assertOk();

        $this->assertDatabaseHas('quotes', ['external_reference' => 'msg-2', 'notes' => 'Nome: João']);
        $this->assertDatabaseHas('quotes', ['external_reference' => 'msg-1', 'notes' => null]);
    }

    public function test_without_recent_order_explains_what_to_do(): void
    {
        $this->withToken($this->bot)->postJson('/api/v1/whatsapp/order-notes', ['phone' => '5521988887777', 'note' => 'Nome: Maria'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Este cliente não tem pedido recente feito por aqui. Se ele ainda vai pedir, guarde o detalhe e envie em notes ao chamar criar_pedido.');
    }
}
