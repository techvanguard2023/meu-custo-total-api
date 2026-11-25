<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baixa de estoque dos insumos da ficha técnica (produção e acabamento/embalagem) quando o produto é vendido:
 *  - sob encomenda: baixa quando o pedido entra em produção (ou pula para pronto/entregue); volta no cancelamento;
 *  - pronta-entrega: a peça já foi feita — os insumos saíram ao registrar a produção, a venda tira só o produto.
 */
class RecipeStockOnSaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    private Material $pla;

    private Material $box;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Loja', 'slug' => 'loja-baixa', 'plan' => Company::PLAN_PRO]);
        $this->company->setting()->create(['default_failure_rate' => 0, 'default_markup' => 0, 'minimum_order_price' => 0]);
        $this->token = User::factory()->create(['company_id' => $this->company->id])->createToken('api')->plainTextToken;

        $pla = MaterialCategory::whereNull('company_id')->where('name', 'PLA')->firstOrFail();
        $packaging = MaterialCategory::whereNull('company_id')->where('name', 'Embalagem')->firstOrFail();
        $this->pla = Material::create([
            'company_id' => $this->company->id, 'name' => 'PLA Preto', 'material_category_id' => $pla->id,
            'spool_weight_g' => 1000, 'spool_cost' => 100, 'cost_per_g' => 0.1, 'stock_quantity' => 1000,
        ]);
        $this->box = Material::create([
            'company_id' => $this->company->id, 'name' => 'Caixinha', 'material_category_id' => $packaging->id,
            'spool_weight_g' => 1, 'spool_cost' => 2, 'cost_per_g' => 2, 'stock_quantity' => 20,
        ]);

        $this->product = Product::forceCreate([
            'company_id' => $this->company->id, 'name' => 'Vaso', 'slug' => 'vaso', 'sale_price' => 40, 'cost' => 7,
            'active' => true, 'made_to_order' => true, 'stock_quantity' => 0,
        ]);
        $this->product->recipeLines()->create(['material_id' => $this->pla->id, 'quantity' => 50]);
        $this->product->recipeLines()->create(['material_id' => $this->box->id, 'quantity' => 1]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    private function assertStock(float $pla, float $box): void
    {
        $this->assertEquals($pla, (float) $this->pla->fresh()->stock_quantity, 'PLA');
        $this->assertEquals($box, (float) $this->box->fresh()->stock_quantity, 'Caixinha');
    }

    public function test_made_to_order_quote_deducts_when_production_starts_and_returns_on_cancel(): void
    {
        $id = $this->api()->postJson('/api/v1/quotes', ['name' => 'Pedido', 'products' => [['product_id' => $this->product->id, 'quantity' => 3]]])
            ->assertCreated()->json('id');

        $this->api()->patchJson("/api/v1/quotes/{$id}/approve", ['payment_method' => 'pix'])->assertOk();
        $this->assertStock(1000, 20); // aprovado, ainda não começou a produzir

        $this->api()->patchJson("/api/v1/quotes/{$id}/production-status", ['production_status' => 'in_production'])->assertOk();
        $this->assertStock(850, 17); // 3 × 50 g e 3 caixinhas

        // Avançar de etapa não baixa de novo
        $this->api()->patchJson("/api/v1/quotes/{$id}/production-status", ['production_status' => 'finished'])->assertOk();
        $this->assertStock(850, 17);

        $this->api()->patchJson("/api/v1/quotes/{$id}/cancel")->assertOk();
        $this->assertStock(1000, 20);
    }

    public function test_cashier_sale_of_made_to_order_item_deducts_when_it_goes_to_production(): void
    {
        $this->api()->postJson('/api/v1/quotes/quick-sale', [
            'payment_method' => 'pix', 'products' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])->assertSuccessful();
        $this->assertStock(1000, 20); // sem peça pronta: vira pedido a produzir

        $id = \App\Models\Quote::latest('id')->value('id');
        $this->api()->patchJson("/api/v1/quotes/{$id}/production-status", ['production_status' => 'delivered'])->assertOk();
        $this->assertStock(900, 18);
    }

    public function test_stock_typed_in_the_form_can_take_only_the_materials(): void
    {
        // Lojista digitou +4 no estoque do cadastro e aceitou tirar os insumos: o estoque do produto não soma de novo
        $this->product->update(['stock_quantity' => 4]);
        $this->api()->postJson("/api/v1/products/{$this->product->id}/production", ['quantity' => 4, 'add_to_stock' => false])->assertSuccessful();

        $this->assertSame(4, $this->product->fresh()->stock_quantity);
        $this->assertStock(800, 16);
    }

    public function test_ready_stock_sale_takes_the_product_and_materials_left_at_production(): void
    {
        // Produziu 2 peças para o estoque: os insumos saem aqui
        $this->api()->postJson("/api/v1/products/{$this->product->id}/production", ['quantity' => 2])->assertSuccessful();
        $this->assertStock(900, 18);
        $this->assertSame(2, $this->product->fresh()->stock_quantity);

        // Vendeu as 2 no Caixa: sai o produto pronto, os insumos não saem de novo
        $this->api()->postJson('/api/v1/quotes/quick-sale', [
            'payment_method' => 'pix', 'products' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])->assertSuccessful();
        $this->assertSame(0, $this->product->fresh()->stock_quantity);
        $this->assertStock(900, 18);
    }
}
