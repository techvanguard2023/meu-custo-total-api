<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Entrega dividida: entregar parte dos itens do pedido (o que já está pronto) e o resto depois. */
class PartialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    private Quote $quote;

    private QuoteItem $ready;

    private QuoteItem $waiting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Loja Entrega', 'slug' => 'loja-entrega', 'plan' => Company::PLAN_PRO]);
        $owner = User::factory()->create(['company_id' => $this->company->id]);
        $this->token = $owner->createToken('api')->plainTextToken;

        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Bia Costa', 'phone' => '21988887777']);
        $this->quote = Quote::forceCreate([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'name' => 'Kit festa', 'status' => Quote::STATUS_APPROVED,
            'production_status' => Quote::PRODUCTION_IN_PRODUCTION, 'quantity' => 1, 'final_price' => 120, 'amount_paid' => 50,
            'print_time_minutes' => 0, 'material_weight_g' => 0, 'approved_at' => now(),
        ]);
        $this->ready = $this->quote->items()->create(['description' => 'Caneca', 'type' => 'product', 'quantity' => 3, 'unit_price' => 20, 'unit_cost' => 8, 'amount' => 60]);
        $this->waiting = $this->quote->items()->create(['description' => 'Topo de bolo', 'type' => 'product', 'quantity' => 1, 'unit_price' => 60, 'unit_cost' => 25, 'amount' => 60]);
    }

    private function as(?string $token = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token);
    }

    private function deliver(array $items, array $extra = [])
    {
        return $this->as()->postJson("/api/v1/quotes/{$this->quote->id}/partial-deliveries", ['items' => $items] + $extra);
    }

    public function test_delivers_part_and_keeps_the_order_in_production(): void
    {
        $res = $this->deliver([['item_id' => $this->ready->id, 'quantity' => 2]], ['note' => 'Retirou na loja'])->assertCreated()
            ->assertJsonPath('completed', false)
            ->assertJsonPath('production_status', Quote::PRODUCTION_IN_PRODUCTION)
            ->assertJsonPath('deliveries.0.items.0.description', 'Caneca')
            ->assertJsonPath('deliveries.0.items.0.quantity', 2)
            ->assertJsonPath('deliveries.0.note', 'Retirou na loja');

        $this->assertSame(2, $this->ready->fresh()->delivered_quantity);
        $this->assertSame(0, $this->waiting->fresh()->delivered_quantity);
        // Valor e recebimento do pedido não mudam
        $this->assertSame('120.00', $this->quote->fresh()->final_price);
        $this->assertSame('50.00', (string) $this->quote->fresh()->amount_paid);

        // O detalhe do pedido e o quadro da Produção trazem quanto já saiu de cada item
        $this->as()->getJson("/api/v1/quotes/{$this->quote->id}")->assertOk()->assertJsonPath('items.0.delivered_quantity', 2);
        $this->as()->getJson('/api/v1/quotes?scope=production')->assertOk()->assertJsonPath('data.0.items.0.delivered_quantity', 2);
    }

    public function test_cannot_deliver_more_than_what_is_left(): void
    {
        $this->deliver([['item_id' => $this->ready->id, 'quantity' => 2]])->assertCreated();

        $this->deliver([['item_id' => $this->ready->id, 'quantity' => 2]])->assertStatus(422)
            ->assertJsonPath('message', '"Caneca": faltam só 1 para entregar.');
        $this->deliver([])->assertStatus(422)->assertJsonValidationErrors('items');

        $other = Quote::forceCreate(['company_id' => $this->company->id, 'name' => 'Outro', 'status' => Quote::STATUS_APPROVED, 'quantity' => 1, 'final_price' => 10, 'print_time_minutes' => 0, 'material_weight_g' => 0]);
        $foreign = $other->items()->create(['description' => 'Outro item', 'type' => 'product', 'quantity' => 1, 'unit_price' => 10, 'amount' => 10]);
        $this->deliver([['item_id' => $foreign->id, 'quantity' => 1]])->assertStatus(422)->assertJsonPath('message', 'Item não encontrado neste pedido.');

        $this->assertSame(1, $this->quote->partialDeliveries()->count());
    }

    public function test_delivering_the_rest_marks_the_order_delivered(): void
    {
        $this->deliver([['item_id' => $this->ready->id, 'quantity' => 3]])->assertCreated()->assertJsonPath('completed', false);

        $this->deliver([['item_id' => $this->waiting->id, 'quantity' => 1]])->assertCreated()
            ->assertJsonPath('completed', true)->assertJsonPath('production_status', Quote::PRODUCTION_DELIVERED);

        $this->assertSame(Quote::PRODUCTION_DELIVERED, $this->quote->fresh()->production_status);
        $this->deliver([['item_id' => $this->waiting->id, 'quantity' => 1]])->assertStatus(422)->assertJsonPath('message', 'Este pedido já foi entregue.');
    }

    public function test_order_with_a_custom_printed_part_is_not_closed_by_its_items(): void
    {
        $this->quote->forceFill(['print_time_minutes' => 90])->save();

        $this->deliver([['item_id' => $this->ready->id, 'quantity' => 3], ['item_id' => $this->waiting->id, 'quantity' => 1]])
            ->assertCreated()->assertJsonPath('completed', false);
        $this->assertSame(Quote::PRODUCTION_IN_PRODUCTION, $this->quote->fresh()->production_status);
    }

    public function test_undo_returns_the_items(): void
    {
        $id = $this->deliver([['item_id' => $this->ready->id, 'quantity' => 2]])->json('deliveries.0.id');

        $this->as()->deleteJson("/api/v1/quotes/{$this->quote->id}/partial-deliveries/{$id}")->assertOk()->assertJsonCount(0, 'deliveries');
        $this->assertSame(0, $this->ready->fresh()->delivered_quantity);
    }

    public function test_notify_reports_when_whatsapp_is_not_connected(): void
    {
        $this->deliver([['item_id' => $this->ready->id, 'quantity' => 1]], ['notify' => true])->assertCreated()
            ->assertJsonPath('notified', false)->assertJsonPath('notify_reason', 'not_connected');
    }

    public function test_only_approved_orders_of_the_store_and_production_team_can_deliver(): void
    {
        $production = User::factory()->create(['company_id' => $this->company->id, 'role' => User::ROLE_PRODUCTION])->createToken('api')->plainTextToken;
        $this->as($production)->postJson("/api/v1/quotes/{$this->quote->id}/partial-deliveries", ['items' => [['item_id' => $this->ready->id, 'quantity' => 1]]])
            ->assertCreated();

        $other = Company::create(['name' => 'Outra', 'slug' => 'outra-entrega', 'plan' => Company::PLAN_PRO]);
        $stranger = User::factory()->create(['company_id' => $other->id])->createToken('api')->plainTextToken;
        $this->as($stranger)->postJson("/api/v1/quotes/{$this->quote->id}/partial-deliveries", ['items' => [['item_id' => $this->ready->id, 'quantity' => 1]]])
            ->assertForbidden();

        $this->quote->forceFill(['status' => Quote::STATUS_SENT])->save();
        $this->deliver([['item_id' => $this->ready->id, 'quantity' => 1]])->assertStatus(422);
    }
}
