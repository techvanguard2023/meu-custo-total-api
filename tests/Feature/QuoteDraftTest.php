<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Orçamento incompleto: salva só com o nome do item ou só com o cliente, mas não aprova até ter um item. */
class QuoteDraftTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Loja Rascunho', 'slug' => 'loja-rascunho', 'plan' => Company::PLAN_PRO]);
        $this->company->setting?->update(['minimum_order_price' => 0]);
        $this->token = User::factory()->create(['company_id' => $this->company->id])->createToken('api')->plainTextToken;
    }

    private function save(array $payload, ?int $id = null)
    {
        return $id
            ? $this->withToken($this->token)->putJson("/api/v1/quotes/{$id}", $payload)
            : $this->withToken($this->token)->postJson('/api/v1/quotes', $payload);
    }

    public function test_quote_with_only_the_item_name_is_saved_but_not_approved(): void
    {
        $id = $this->save(['name' => 'Topo de bolo'])->assertCreated()
            ->assertJsonPath('name', 'Topo de bolo')->assertJsonPath('is_incomplete', true)->json('id');

        $this->withToken($this->token)->patchJson("/api/v1/quotes/{$id}/approve", ['payment_method' => 'pix'])->assertStatus(422)
            ->assertJsonPath('message', 'Orçamento incompleto: adicione um produto ou um item com tempo de produção antes de aprovar.');
        $this->assertSame(Quote::STATUS_SENT, Quote::find($id)->status);

        // Completo depois: aprova
        $this->save(['name' => 'Topo de bolo', 'print_time_minutes' => 90], $id)->assertOk()->assertJsonPath('is_incomplete', false);
        $this->withToken($this->token)->patchJson("/api/v1/quotes/{$id}/approve", ['payment_method' => 'pix'])->assertOk()
            ->assertJsonPath('status', Quote::STATUS_APPROVED);
    }

    public function test_quote_with_only_the_customer_gets_a_name(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Ana Lima']);

        $this->save(['customer_id' => $customer->id])->assertCreated()
            ->assertJsonPath('name', 'Orçamento de Ana Lima')->assertJsonPath('is_incomplete', true);
    }

    public function test_empty_quote_is_refused(): void
    {
        $this->save(['name' => '  '])->assertStatus(422)
            ->assertJsonPath('message', 'Preencha pelo menos o nome do item ou escolha o cliente para salvar.');
        $this->assertSame(0, Quote::count());
    }
}
