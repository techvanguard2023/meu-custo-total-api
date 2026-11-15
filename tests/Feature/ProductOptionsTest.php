<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\OptionPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Loja Opcoes', 'slug' => 'loja-opcoes', 'plan' => Company::PLAN_PRO, 'catalog_enabled' => true, 'catalog_token' => 'token-opcoes']);
        $this->user = User::factory()->create(['company_id' => $company->id]);
        $this->token = $this->user->createToken('api')->plainTextToken;
    }

    private function sampleGroups(): array
    {
        return [
            ['name' => 'Acabamento', 'type' => 'single', 'required' => true, 'options' => [
                ['name' => 'Fosco', 'price_delta' => 0],
                ['name' => 'Brilho', 'price_delta' => 2.5, 'cost_delta' => 1],
                ['name' => 'Verniz', 'price_delta' => 4],
            ]],
            ['name' => 'Extras', 'type' => 'multiple', 'required' => false, 'options' => [
                ['name' => 'Laço', 'price_delta' => 1],
                ['name' => 'Cartão', 'price_delta' => 3, 'active' => true],
            ]],
        ];
    }

    private function product(array $extra = []): array
    {
        return $this->withToken($this->token)->postJson('/api/v1/products', $extra + [
            'name' => 'Caneca', 'cost' => 10, 'sale_price' => 30, 'stock_quantity' => 100, 'option_groups' => $this->sampleGroups(),
        ])->assertCreated()->json();
    }

    private function optionId(array $product, string $name): int
    {
        return collect($product['option_groups'])->flatMap(fn ($g) => $g['options'])->firstWhere('name', $name)['id'];
    }

    public function test_option_groups_are_saved_and_synced(): void
    {
        $product = $this->product();

        $this->assertCount(2, $product['option_groups']);
        $this->assertSame('Acabamento', $product['option_groups'][0]['name']);
        $this->assertTrue($product['option_groups'][0]['required']);
        $this->assertSame('multiple', $product['option_groups'][1]['type']);
        $this->assertCount(3, $product['option_groups'][0]['options']);

        // Atualiza uma opção pelo id, apaga a grade "Extras" e cria uma nova opção
        $brilho = $this->optionId($product, 'Brilho');
        $groups = [['id' => $product['option_groups'][0]['id'], 'name' => 'Acabamento', 'type' => 'single', 'required' => true, 'options' => [
            ['id' => $brilho, 'name' => 'Brilho forte', 'price_delta' => 3],
            ['name' => 'Acetinado', 'price_delta' => 1],
        ]]];
        $updated = $this->withToken($this->token)->putJson("/api/v1/products/{$product['id']}", ['name' => 'Caneca', 'cost' => 10, 'option_groups' => $groups])
            ->assertOk()->json();

        $this->assertCount(1, $updated['option_groups']);
        $this->assertSame(['Brilho forte', 'Acetinado'], array_column($updated['option_groups'][0]['options'], 'name'));
        $this->assertSame($brilho, $updated['option_groups'][0]['options'][0]['id']);
        $this->assertDatabaseCount('product_options', 2);

        // Sem o campo, nada muda
        $this->withToken($this->token)->putJson("/api/v1/products/{$product['id']}", ['name' => 'Caneca', 'cost' => 10])->assertOk()->assertJsonCount(1, 'option_groups');
    }

    public function test_group_validation(): void
    {
        $bad = fn (array $groups) => $this->withToken($this->token)->postJson('/api/v1/products', ['name' => 'X', 'cost' => 1, 'option_groups' => $groups]);

        $bad([['name' => '', 'options' => [['name' => 'A']]]])->assertStatus(422)->assertJsonValidationErrors('option_groups.0.name');
        $bad([['name' => 'G', 'options' => []]])->assertStatus(422)->assertJsonValidationErrors('option_groups.0.options');
        $bad([['name' => 'G', 'type' => 'varias', 'options' => [['name' => 'A']]]])->assertStatus(422)->assertJsonValidationErrors('option_groups.0.type');
        $bad([['name' => 'G', 'options' => [['name' => 'A', 'price_delta' => -1]]]])->assertStatus(422)->assertJsonValidationErrors('option_groups.0.options.0.price_delta');
    }

    public function test_options_add_to_price_and_cost_and_are_named_on_the_item(): void
    {
        $product = $this->product();
        $brilho = $this->optionId($product, 'Brilho');
        $laco = $this->optionId($product, 'Laço');

        $line = $this->withToken($this->token)->postJson('/api/v1/quotes/preview', [
            'name' => 'T', 'quantity' => 1,
            'products' => [['product_id' => $product['id'], 'quantity' => 2, 'option_ids' => [$brilho, $laco]]],
        ])->assertOk()->json('products.0');

        // 30 + 2,50 (brilho) + 1 (laço) = 33,50 por unidade; custo 10 + 1 = 11
        $this->assertSame(33.5, $line['unit_price']);
        $this->assertEquals(67.0, $line["line_total"]);
        $this->assertEquals(11.0, $line["unit_cost"]);
        $this->assertSame(33.5, $line['regular_unit_price']);
        $this->assertSame('Caneca — Acabamento: Brilho · Extras: Laço', $line['name']);
        $this->assertSame(['Brilho', 'Laço'], array_column($line['options'], 'name'));
    }

    public function test_required_group_must_be_chosen_and_single_allows_only_one(): void
    {
        $product = $this->product();
        $order = fn (array $line) => $this->withToken($this->token)->postJson('/api/v1/quotes/preview', [
            'name' => 'T', 'quantity' => 1, 'products' => [['product_id' => $product['id'], 'quantity' => 1] + $line],
        ]);

        $order([])->assertStatus(422)->assertJsonPath('message', 'Escolha uma opção de "Acabamento" para o produto "Caneca". Opções: Fosco, Brilho, Verniz.');
        $order(['option_ids' => [$this->optionId($product, 'Brilho'), $this->optionId($product, 'Verniz')]])
            ->assertStatus(422)->assertJsonPath('message', 'Escolha só uma opção de "Acabamento" em "Caneca".');
        $order(['option_ids' => [999999]])->assertStatus(422);
        $order(['option_ids' => [$this->optionId($product, 'Fosco')]])->assertOk();
    }

    public function test_option_of_another_product_is_refused(): void
    {
        $product = $this->product();
        $other = $this->product(['name' => 'Outra']);

        $this->withToken($this->token)->postJson('/api/v1/quotes/preview', [
            'name' => 'T', 'quantity' => 1, 'products' => [['product_id' => $product['id'], 'quantity' => 1, 'option_ids' => [$this->optionId($other, 'Fosco')]]],
        ])->assertStatus(422);
    }

    public function test_bot_order_resolves_options_by_name_and_keeps_a_snapshot(): void
    {
        $product = $this->product();
        $integration = $this->user->createToken('whatsapp-bot')->plainTextToken;

        $this->withToken($integration)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Maria', 'customer_phone' => '21999990000',
            'products' => [['product_id' => $product['id'], 'quantity' => 1, 'option_labels' => ['verniz', 'Extras: Cartão']]],
        ])->assertSuccessful();

        $item = \App\Models\QuoteItem::where('product_id', $product['id'])->firstOrFail();
        $this->assertSame('Caneca — Acabamento: Verniz · Extras: Cartão', $item->description);
        $this->assertEquals(37.0, (float) $item->unit_price);
        $this->assertSame(['Verniz', 'Cartão'], array_column($item->options, 'name'));

        // Apagar a opção depois não mexe no pedido já feito
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->putJson("/api/v1/products/{$product['id']}", ['name' => 'Caneca', 'cost' => 10, 'option_groups' => []])->assertOk();
        $this->assertSame('Caneca — Acabamento: Verniz · Extras: Cartão', $item->fresh()->description);
    }

    public function test_bot_gets_a_readable_error_listing_the_options(): void
    {
        $product = $this->product();
        $integration = $this->user->createToken('whatsapp-bot')->plainTextToken;

        $this->withToken($integration)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Maria', 'customer_phone' => '21999990000',
            'products' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('message', 'Escolha uma opção de "Acabamento" para o produto "Caneca". Opções: Fosco, Brilho, Verniz.');

        $this->app['auth']->forgetGuards();
        $this->withToken($integration)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Maria', 'customer_phone' => '21999990000',
            'products' => [['product_id' => $product['id'], 'quantity' => 1, 'option_labels' => ['Cetim']]],
        ])->assertStatus(422)->assertJsonPath('message', 'Opção "Cetim" não existe em "Caneca". Opções: Acabamento (Fosco, Brilho, Verniz); Extras (Laço, Cartão).');
    }

    public function test_catalog_exposes_active_options_without_cost(): void
    {
        $product = $this->product(['option_groups' => [
            ['name' => 'Acabamento', 'required' => true, 'options' => [['name' => 'Fosco', 'cost_delta' => 1], ['name' => 'Oculta', 'active' => false]]],
            ['name' => 'Só inativas', 'options' => [['name' => 'Nada', 'active' => false]]],
        ]]);

        $groups = collect($this->getJson('/api/v1/public/catalog/loja-opcoes')->assertOk()->json('products'))->firstWhere('id', $product['id'])['option_groups'];

        $this->assertCount(1, $groups);
        $this->assertSame('Acabamento', $groups[0]['name']);
        $this->assertSame(['Fosco'], array_column($groups[0]['options'], 'name'));
        $this->assertArrayNotHasKey('cost_delta', $groups[0]['options'][0]);
    }

    public function test_product_without_groups_ignores_stray_options(): void
    {
        $product = $this->withToken($this->token)->postJson('/api/v1/products', ['name' => 'Simples', 'cost' => 5, 'sale_price' => 10])->json();

        $this->withToken($this->token)->postJson('/api/v1/quotes/preview', [
            'name' => 'T', 'quantity' => 1,
            'products' => [['product_id' => $product['id'], 'quantity' => 1, 'option_labels' => ['Qualquer']]],
        ])->assertOk()->assertJsonPath('products.0.name', 'Simples');
    }

    public function test_picker_text_groups_choices(): void
    {
        $this->assertSame(
            'Extras: Laço, Cartão · Acabamento: Fosco',
            OptionPicker::text([
                ['group' => 'Extras', 'name' => 'Laço'],
                ['group' => 'Extras', 'name' => 'Cartão'],
                ['group' => 'Acabamento', 'name' => 'Fosco'],
            ])
        );
        $this->assertSame(3.5, OptionPicker::priceDelta([['price_delta' => 1], ['price_delta' => 2.5]]));
    }
}
