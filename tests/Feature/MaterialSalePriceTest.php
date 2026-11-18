<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Material;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Material vendido direto ao cliente: a loja compra por um preço e vende por outro. Com preço de venda no cadastro,
 * o orçamento cobra esse preço (sem markup nem taxa de falha); o custo continua no custo e no lucro do pedido.
 */
class MaterialSalePriceTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Loja Insumo', 'slug' => 'loja-insumo', 'plan' => Company::PLAN_PRO]);
        $company->setting?->update(['minimum_order_price' => 0]);
        $this->token = User::factory()->create(['company_id' => $company->id])->createToken('api')->plainTextToken;
    }

    private function material(array $data): int
    {
        return $this->withToken($this->token)->postJson('/api/v1/materials', $data + ['type' => 'Componente'])->assertCreated()->json('id');
    }

    public function test_sale_price_is_optional_and_gives_the_price_per_unit(): void
    {
        $switch = $this->material(['name' => 'Switch Azul', 'spool_weight_g' => 20, 'spool_cost' => 36, 'cost_per_g' => 1.8, 'sale_price' => 100]);
        $plain = $this->material(['name' => 'Parafuso', 'spool_weight_g' => 100, 'spool_cost' => 10, 'cost_per_g' => 0.1]);

        $this->assertSame('100.00', Material::find($switch)->sale_price);
        $this->assertSame('5.0000', Material::find($switch)->sale_price_per_unit);
        $this->assertNull(Material::find($plain)->sale_price_per_unit);

        // Tirar o preço de venda volta a ser só insumo
        $this->withToken($this->token)->putJson("/api/v1/materials/{$switch}", ['name' => 'Switch Azul', 'spool_weight_g' => 20, 'spool_cost' => 36, 'cost_per_g' => 1.8, 'sale_price' => null])->assertOk();
        $this->assertNull(Material::find($switch)->sale_price_per_unit);
    }

    public function test_quote_charges_the_sale_price_without_markup_and_keeps_the_cost(): void
    {
        $switch = $this->material(['name' => 'Switch Azul', 'spool_weight_g' => 20, 'spool_cost' => 36, 'cost_per_g' => 1.8, 'sale_price' => 100]);
        $pla = $this->material(['name' => 'PLA Preto', 'spool_weight_g' => 1000, 'spool_cost' => 120, 'cost_per_g' => 0.12]);

        $payload = [
            'name' => 'Teclado', 'quantity' => 2, 'print_time_minutes' => 60, 'markup_percent' => 100, 'failure_rate_percent' => 10,
            'materials' => [['material_id' => $pla, 'weight' => 50], ['material_id' => $switch, 'weight' => 3]],
        ];

        $preview = $this->withToken($this->token)->postJson('/api/v1/quotes/preview', $payload)->assertOk();
        // Custos: PLA 50g × 0,12 × 2 = 12,00; switch 3 × 1,80 × 2 = 10,80. Falha só no PLA: 1,20.
        // Preço: (12,00 + 1,20) × 2 = 26,40 da peça + switch pelo preço de venda 3 × 5,00 × 2 = 30,00
        $preview->assertJsonPath('material_cost', 22.8)
            ->assertJsonPath('failure_cost', 1.2)
            ->assertJsonPath('sold_materials_cost', 10.8)
            ->assertJsonPath('sold_materials_total', 30)
            ->assertJsonPath('materials.1.unit_price', 5)
            ->assertJsonPath('materials.1.line_price', 30)
            ->assertJsonPath('materials.0.unit_price', null)
            ->assertJsonPath('final_price', 56.4)
            ->assertJsonPath('profit_amount', 32.4);

        $id = $this->withToken($this->token)->postJson('/api/v1/quotes', $payload)->assertCreated()->json('id');
        $quote = Quote::with('items')->find($id);

        $this->assertSame('5.00', (string) $quote->items->firstWhere('material_id', $switch)->unit_price);
        $this->assertSame('0.00', (string) $quote->items->firstWhere('material_id', $pla)->unit_price);

        // O cliente vê o switch numa linha própria e a peça com o resto (PDF, "Meus pedidos", mensagens)
        $lines = $quote->soldLines();
        $this->assertSame(['description' => 'Switch Azul', 'quantity' => 6, 'unit_price' => 5.0, 'amount' => 30.0], $lines[0]);
        $this->assertSame('Teclado', $lines[1]['description']);
        $this->assertEqualsWithDelta(26.4, $lines[1]['amount'], 0.001);
    }

    public function test_quote_with_only_a_sold_material_lists_it(): void
    {
        $switch = $this->material(['name' => 'Switch Azul', 'spool_weight_g' => 20, 'spool_cost' => 36, 'cost_per_g' => 1.8, 'sale_price' => 100]);

        $id = $this->withToken($this->token)->postJson('/api/v1/quotes', [
            'name' => 'Switches avulsos', 'quantity' => 1, 'print_time_minutes' => 1, 'markup_percent' => 100, 'failure_rate_percent' => 0,
            'materials' => [['material_id' => $switch, 'weight' => 10]],
        ])->assertCreated()->assertJsonPath('final_price', '50.00')->json('id');

        $this->assertSame([['description' => 'Switch Azul', 'quantity' => 10, 'unit_price' => 5.0, 'amount' => 50.0]], Quote::with('items')->find($id)->soldLines());
    }
}
