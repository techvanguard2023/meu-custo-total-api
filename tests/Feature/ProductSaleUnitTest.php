<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSaleUnitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Loja Pacote', 'slug' => 'loja-pacote', 'plan' => Company::PLAN_PRO, 'catalog_enabled' => true, 'catalog_token' => 'token-de-teste']);
        $this->user = User::factory()->create(['company_id' => $company->id]);
        $this->token = $this->user->createToken('api')->plainTextToken;
    }

    public function test_product_sold_by_pack_with_minimum(): void
    {
        $id = $this->createProduct(['sale_unit' => 'pack', 'pack_size' => 10, 'pack_label' => ' Caixa ', 'min_quantity' => 3])
            ->assertCreated()
            ->assertJsonPath('sale_unit', 'pack')
            ->assertJsonPath('pack_size', 10)
            ->assertJsonPath('pack_label', 'Caixa')
            ->assertJsonPath('min_quantity', 3)
            ->assertJsonPath('sale_unit_label', 'Caixa com 10')
            ->json('id');

        $this->assertDatabaseHas('products', ['id' => $id, 'sale_unit' => 'pack', 'pack_size' => 10, 'min_quantity' => 3]);
    }

    public function test_defaults_keep_existing_behavior(): void
    {
        $this->createProduct([])->assertCreated()
            ->assertJsonPath('sale_unit', 'unit')
            ->assertJsonPath('pack_size', null)
            ->assertJsonPath('min_quantity', 1)
            ->assertJsonPath('sale_unit_label', null);
    }

    public function test_pack_needs_a_size_and_unit_clears_it(): void
    {
        $this->createProduct(['sale_unit' => 'pack'])->assertStatus(422)->assertJsonValidationErrors('pack_size');
        $this->createProduct(['sale_unit' => 'pack', 'pack_size' => 1])->assertStatus(422)->assertJsonValidationErrors('pack_size');
        $this->createProduct(['min_quantity' => 0])->assertStatus(422)->assertJsonValidationErrors('min_quantity');

        $id = $this->createProduct(['sale_unit' => 'pack', 'pack_size' => 6])->json('id');
        $this->withToken($this->token)->putJson("/api/v1/products/{$id}", ['name' => 'Produto', 'cost' => 5, 'sale_unit' => 'unit'])
            ->assertOk()->assertJsonPath('pack_size', null)->assertJsonPath('pack_label', null);
    }

    public function test_variation_accepts_up_to_eight_colors(): void
    {
        $colors = fn (int $n) => array_map(fn ($i) => sprintf('#%02X%02X%02X', $i * 20, 100, 200), range(1, $n));
        $payload = fn (int $n) => ['variations' => [['color' => 'Arco-íris', 'color_hexes' => $colors($n), 'stock_quantity' => 1]]];

        $this->createProduct($payload(8))->assertCreated()->assertJsonCount(8, 'variations.0.color_hexes');
        $this->createProduct($payload(9))->assertStatus(422)->assertJsonValidationErrors('variations.0.color_hexes');
    }

    public function test_wholesale_flag_is_kept_on_price_tiers(): void
    {
        $this->createProduct(['price_tiers' => [
            ['min_quantity' => 10, 'type' => 'fixed', 'value' => 7.5, 'wholesale' => true],
            ['min_quantity' => 3, 'type' => 'percent', 'value' => 5],
        ]])->assertCreated()
            ->assertJsonPath('price_tiers.0.min_quantity', 3)
            ->assertJsonPath('price_tiers.0.wholesale', false)
            ->assertJsonPath('price_tiers.1.wholesale', true);
    }

    public function test_catalog_exposes_pack_and_minimum(): void
    {
        $this->createProduct(['name' => 'Chaveiro', 'sale_price' => 12, 'sale_unit' => 'pack', 'pack_size' => 10, 'min_quantity' => 2, 'stock_quantity' => 5])->assertCreated();
        $this->createProduct(['name' => 'Caneca', 'sale_price' => 30, 'stock_quantity' => 5])->assertCreated();

        $products = collect($this->getJson('/api/v1/public/catalog/loja-pacote')->assertOk()->json('products'))->keyBy('name');

        $this->assertSame('pack', $products['Chaveiro']['sale_unit']);
        $this->assertSame(10, $products['Chaveiro']['pack_size']);
        $this->assertSame('pacote', $products['Chaveiro']['pack_label']);
        $this->assertSame('pacote com 10', $products['Chaveiro']['sale_unit_label']);
        $this->assertSame(2, $products['Chaveiro']['min_quantity']);
        $this->assertSame('unit', $products['Caneca']['sale_unit']);
        $this->assertSame(1, $products['Caneca']['min_quantity']);
        $this->assertNull($products['Caneca']['pack_size']);
    }

    public function test_bot_order_below_minimum_is_refused_and_at_minimum_is_accepted(): void
    {
        $id = $this->createProduct(['name' => 'Chaveiro', 'sale_price' => 12, 'sale_unit' => 'pack', 'pack_size' => 10, 'pack_label' => 'pacote', 'min_quantity' => 3, 'stock_quantity' => 50])->json('id');
        $integration = $this->user->createToken('whatsapp-bot')->plainTextToken;
        $order = fn (int $quantity) => $this->withToken($integration)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Maria', 'customer_phone' => '21999990000', 'products' => [['product_id' => $id, 'quantity' => $quantity]],
        ]);

        $order(2)->assertStatus(422)
            ->assertJsonPath('message', 'Quantidade abaixo do mínimo: "Chaveiro" tem pedido mínimo de 3 pacotes (veio 2). Pergunte ao cliente se quer aumentar a quantidade.');
        $this->assertDatabaseCount('quotes', 0);

        $this->app['auth']->forgetGuards();
        $order(3)->assertSuccessful();
        $this->assertDatabaseHas('quote_items', ['product_id' => $id, 'description' => 'Chaveiro — pacote com 10', 'quantity' => 3]);
    }

    public function test_quote_preview_flags_the_minimum_for_the_screens(): void
    {
        $id = $this->createProduct(['name' => 'Chaveiro', 'sale_price' => 12, 'min_quantity' => 5, 'stock_quantity' => 50])->json('id');

        $line = $this->withToken($this->token)->postJson('/api/v1/quotes/preview', [
            'name' => 'Teste', 'quantity' => 1, 'products' => [['product_id' => $id, 'quantity' => 2]],
        ])->assertOk()->json('products.0');

        $this->assertSame(5, $line['min_quantity']);
        $this->assertSame(2, $line['product_quantity']);
    }

    public function test_quantity_text_pluralizes_the_pack_name(): void
    {
        $text = fn (string $label, int $n) => (new Product(['sale_unit' => 'pack', 'pack_size' => 4, 'pack_label' => $label]))->quantityText($n);

        $this->assertSame('1 pacote', $text('pacote', 1));
        $this->assertSame('3 caixas', $text('Caixa', 3));
        $this->assertSame('2 cartelas', $text('cartela', 2));
        $this->assertSame('2 pares', $text('par', 2));
        $this->assertSame('2 cartões', $text('cartão', 2));
        $this->assertSame('3 itens', $text('item', 3));
        $this->assertSame('5 unidades', (new Product)->quantityText(5));
        $this->assertSame('1 unidade', (new Product)->quantityText(1));
    }

    private function createProduct(array $attributes)
    {
        return $this->withToken($this->token)->postJson('/api/v1/products', $attributes + ['name' => 'Produto '.uniqid(), 'cost' => 5, 'sale_price' => 10]);
    }
}
