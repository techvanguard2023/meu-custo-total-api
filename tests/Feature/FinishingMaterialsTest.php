<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ficha técnica com "Acabamento e embalagem": entra no custo, mas fica fora da taxa de falha. */
class FinishingMaterialsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Loja', 'slug' => 'loja-acab', 'plan' => Company::PLAN_PRO]);
        $this->company->setting()->create(['default_failure_rate' => 10, 'default_markup' => 0, 'minimum_order_price' => 0]);
        $this->token = User::factory()->create(['company_id' => $this->company->id])->createToken('api')->plainTextToken;
    }

    private function material(string $categoryName, float $costPerUnit): Material
    {
        $category = MaterialCategory::whereNull('company_id')->where('name', $categoryName)->firstOrFail();

        return Material::create([
            'company_id' => $this->company->id, 'name' => "{$categoryName} teste", 'material_category_id' => $category->id,
            'spool_weight_g' => 1, 'spool_cost' => $costPerUnit, 'cost_per_g' => $costPerUnit,
        ]);
    }

    public function test_packaging_counts_in_the_cost_without_the_failure_rate(): void
    {
        $filament = $this->material('PLA', 0.1);     // 50 g = R$ 5,00
        $box = $this->material('Embalagem', 3);      // 1 caixa = R$ 3,00

        $cost = $this->withToken($this->token)->postJson('/api/v1/products/cost-preview', [
            'recipe' => [['material_id' => $filament->id, 'quantity' => 50], ['material_id' => $box->id, 'quantity' => 1]],
            'print_time_minutes' => 0, 'postprocess_minutes' => 0, 'printer_id' => null,
        ])->assertOk();

        $cost->assertJsonPath('material_cost', 8)->assertJsonPath('finishing_cost', 3);
        // Falha de 10% só sobre o filamento (R$ 5,00), não sobre a caixa
        $this->assertEquals(0.5, $cost->json('failure_cost'));
        $this->assertEquals(8.5, $cost->json('total'));
    }

    public function test_store_category_can_be_marked_as_finishing_and_costs_are_refreshed(): void
    {
        $id = $this->withToken($this->token)->postJson('/api/v1/material-categories', ['name' => 'Fitas', 'pricing_unit' => 'unit'])
            ->assertCreated()->assertJsonPath('role', 'production')->json('id');

        $ribbon = Material::create([
            'company_id' => $this->company->id, 'name' => 'Fita cetim', 'material_category_id' => $id,
            'spool_weight_g' => 1, 'spool_cost' => 2, 'cost_per_g' => 2,
        ]);
        $product = Product::forceCreate(['company_id' => $this->company->id, 'name' => 'Laço', 'slug' => 'laco', 'auto_cost' => true, 'cost' => 0]);
        $product->recipeLines()->create(['material_id' => $ribbon->id, 'quantity' => 1]);
        app(\App\Services\ProductRecipe::class)->refreshCost($product);
        $this->assertEquals(2.2, (float) $product->fresh()->cost);

        $this->withToken($this->token)->putJson("/api/v1/material-categories/{$id}", ['name' => 'Fitas', 'role' => 'finishing'])
            ->assertOk()->assertJsonPath('role', 'finishing');
        $this->assertEquals(2.0, (float) $product->fresh()->cost);
    }

    public function test_default_packaging_categories_are_finishing(): void
    {
        $this->assertSame('finishing', MaterialCategory::whereNull('company_id')->where('name', 'Embalagem')->value('role'));
        $this->assertSame('production', MaterialCategory::whereNull('company_id')->where('name', 'PLA')->value('role'));
        $this->assertSame('finishing', MaterialCategory::whereNull('company_id')->where('name', 'Tintas e Acabamento')->value('role'));
    }
}
