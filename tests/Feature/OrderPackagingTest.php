<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Embalagem do pedido: uma vez por pedido, só no custo, e baixa o estoque na aprovação (volta no cancelamento). */
class OrderPackagingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    private Material $bag;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Loja', 'slug' => 'loja-emb', 'plan' => Company::PLAN_PRO]);
        $this->company->setting()->create(['default_failure_rate' => 0, 'default_markup' => 0, 'minimum_order_price' => 0]);
        $this->token = User::factory()->create(['company_id' => $this->company->id])->createToken('api')->plainTextToken;

        $category = MaterialCategory::whereNull('company_id')->where('name', 'Embalagem')->firstOrFail();
        $this->bag = Material::create([
            'company_id' => $this->company->id, 'name' => 'Sacola kraft', 'material_category_id' => $category->id,
            'spool_weight_g' => 1, 'spool_cost' => 1.5, 'cost_per_g' => 1.5, 'stock_quantity' => 10,
        ]);
        $this->product = Product::forceCreate([
            'company_id' => $this->company->id, 'name' => 'Chaveiro', 'slug' => 'chaveiro', 'sale_price' => 20, 'cost' => 5, 'active' => true, 'stock_quantity' => 50,
        ]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    public function test_packaging_is_charged_once_per_order_as_cost_only(): void
    {
        $this->api()->putJson('/api/v1/settings/order-packaging', ['lines' => [['material_id' => $this->bag->id, 'quantity' => 1]]])
            ->assertOk()->assertJsonPath('order_packaging.0.material_id', $this->bag->id);

        $preview = $this->api()->postJson('/api/v1/quotes/preview', [
            'name' => 'Pedido', 'products' => [['product_id' => $this->product->id, 'quantity' => 5]],
        ])->assertOk();

        // 5 chaveiros, uma sacola só: o preço para o cliente não muda, o lucro cai R$ 1,50
        $this->assertEquals(100, $preview->json('final_price'));
        $this->assertEquals(1.5, $preview->json('order_packaging_cost'));
        $this->assertEquals(100 - 25 - 1.5, $preview->json('profit_amount'));
    }

    public function test_packaging_leaves_stock_on_approval_and_returns_on_cancel(): void
    {
        $this->company->setting->update(['order_packaging' => [['material_id' => $this->bag->id, 'quantity' => 2]]]);

        $id = $this->api()->postJson('/api/v1/quotes', [
            'name' => 'Pedido', 'products' => [['product_id' => $this->product->id, 'quantity' => 3]],
        ])->assertCreated()->assertJsonPath('order_packaging_cost', '3.00')->json('id');

        $this->assertSame(1, QuoteItem::where('quote_id', $id)->where('type', QuoteItem::TYPE_PACKAGING)->count());
        $this->assertEquals(10, (float) $this->bag->fresh()->stock_quantity);

        $res = $this->api()->patchJson("/api/v1/quotes/{$id}/approve", ['payment_method' => 'pix']);
        $this->assertSame(200, $res->status(), (string) $res->json('message'));
        $this->assertEquals(8, (float) $this->bag->fresh()->stock_quantity);

        $this->api()->patchJson("/api/v1/quotes/{$id}/cancel")->assertOk();
        $this->assertEquals(10, (float) $this->bag->fresh()->stock_quantity);
    }

    public function test_product_unit_cost_ignores_order_packaging(): void
    {
        $this->company->setting->update(['order_packaging' => [['material_id' => $this->bag->id, 'quantity' => 1]]]);

        $this->api()->postJson('/api/v1/products/cost-preview', [
            'recipe' => [['material_id' => $this->bag->id, 'quantity' => 1]], 'print_time_minutes' => 0, 'postprocess_minutes' => 0, 'printer_id' => null,
        ])->assertOk()->assertJsonPath('total', 1.5);
    }

    public function test_material_from_another_store_is_refused(): void
    {
        $other = Company::create(['name' => 'Outra', 'slug' => 'outra-emb', 'plan' => Company::PLAN_PRO]);
        $foreign = Material::create(['company_id' => $other->id, 'name' => 'Caixa alheia', 'spool_weight_g' => 1, 'spool_cost' => 1, 'cost_per_g' => 1]);

        $this->api()->putJson('/api/v1/settings/order-packaging', ['lines' => [['material_id' => $foreign->id, 'quantity' => 1]]])
            ->assertStatus(422);
    }
}
