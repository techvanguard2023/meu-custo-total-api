<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Botão "Duplicar" das listas de Produtos e Materiais. */
class DuplicateCatalogItemsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->company = Company::create(['name' => 'Loja', 'slug' => 'loja-dup', 'plan' => Company::PLAN_PRO]);
        $this->token = User::factory()->create(['company_id' => $this->company->id])->createToken('api')->plainTextToken;
    }

    private function material(array $extra = []): Material
    {
        return Material::create($extra + [
            'company_id' => $this->company->id, 'name' => 'PLA Azul', 'brand' => 'Voolt', 'type' => 'PLA', 'color' => 'Azul',
            'spool_weight_g' => 1000, 'spool_cost' => 100, 'cost_per_g' => 0.1, 'stock_quantity' => 800,
        ]);
    }

    public function test_product_is_duplicated_with_variations_options_recipe_and_photos(): void
    {
        $material = $this->material();
        Storage::disk('public')->put('products/foto.jpg', 'imagem');

        $product = Product::forceCreate([
            'company_id' => $this->company->id, 'name' => 'Vaso Zen', 'slug' => 'vaso-zen', 'sku' => 'VZ-1', 'sale_price' => 50,
            'stock_quantity' => 7, 'show_in_catalog' => true, 'active' => true,
        ]);
        $variation = $product->variations()->create(['color' => 'Azul', 'sale_price' => 55, 'stock_quantity' => 3, 'sku' => 'VZ-AZ']);
        $product->recipeLines()->create(['material_id' => $material->id, 'quantity' => 40]);
        $product->recipeLines()->create(['material_id' => $material->id, 'product_variation_id' => $variation->id, 'quantity' => 10]);
        $group = $product->optionGroups()->create(['name' => 'Acabamento', 'type' => 'single', 'required' => true]);
        $group->options()->create(['name' => 'Fosco', 'price_delta' => 5]);
        $product->images()->create(['path' => 'products/foto.jpg', 'position' => 0]);

        $res = $this->withToken($this->token)->postJson("/api/v1/products/{$product->id}/duplicate")->assertCreated()
            ->assertJsonPath('name', 'Vaso Zen (cópia)')
            ->assertJsonPath('sku', null)
            ->assertJsonPath('stock_quantity', 0)
            ->assertJsonPath('show_in_catalog', false);

        $copy = Product::with(['variations', 'recipeLines', 'optionGroups.options', 'images'])->find($res->json('id'));
        $this->assertNotSame($product->slug, $copy->slug);
        $this->assertCount(1, $copy->variations);
        $this->assertSame(0, $copy->variations[0]->stock_quantity);
        $this->assertNull($copy->variations[0]->sku);
        $this->assertSame('Fosco', $copy->optionGroups[0]->options[0]->name);
        // Ficha da variação aponta para a variação da cópia, não para a do original
        $this->assertEqualsCanonicalizing([null, $copy->variations[0]->id], $copy->recipeLines->pluck('product_variation_id')->all());
        // Foto é um arquivo novo
        $this->assertNotSame('products/foto.jpg', $copy->images[0]->path);
        Storage::disk('public')->assertExists($copy->images[0]->path);

        // Original intacto
        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertCount(2, $product->recipeLines()->get());
    }

    public function test_material_is_duplicated_with_stock_zeroed(): void
    {
        $material = $this->material();

        $this->withToken($this->token)->postJson("/api/v1/materials/{$material->id}/duplicate")->assertCreated()
            ->assertJsonPath('name', 'PLA Azul (cópia)')
            ->assertJsonPath('brand', 'Voolt');

        $copy = Material::where('name', 'PLA Azul (cópia)')->first();
        $this->assertEquals(0, (float) $copy->stock_quantity);
        $this->assertEquals(0.1, (float) $copy->cost_per_g);
    }

    public function test_cannot_duplicate_another_stores_items(): void
    {
        $other = Company::create(['name' => 'Outra', 'slug' => 'outra-dup', 'plan' => Company::PLAN_PRO]);
        $product = Product::forceCreate(['company_id' => $other->id, 'name' => 'Alheio', 'slug' => 'alheio', 'sale_price' => 10]);

        $this->withToken($this->token)->postJson("/api/v1/products/{$product->id}/duplicate")->assertStatus(403);
        $this->assertSame(1, Product::count());
    }
}
