<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ShippingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MelhorEnvioTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.melhorenvio.client_id' => 'app-id',
            'services.melhorenvio.client_secret' => 'app-secret',
            'services.melhorenvio.sandbox' => true,
            'services.melhorenvio.test_access_token' => null,
            'services.frontend_url' => 'https://app.exemplo.com.br',
        ]);
        $this->company = Company::create(['name' => 'Loja Frete', 'slug' => 'loja-frete', 'plan' => Company::PLAN_ESSENTIAL, 'catalog_enabled' => true, 'catalog_token' => 'token-frete']);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->token = $this->user->createToken('api')->plainTextToken;
    }

    private function rules(array $over = []): array
    {
        return $over + ['enabled' => true, 'origin_cep' => '01001-000', 'weight_g' => 300, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10, 'handling_days' => 2];
    }

    private function connect(): void
    {
        ShippingAccount::create(['company_id' => $this->company->id, 'access_token' => 'tok-loja', 'refresh_token' => 'ref-loja', 'expires_at' => now()->addDays(20), 'connected_at' => now()]);
    }

    private function fakeCalculate(): void
    {
        Http::fake(['sandbox.melhorenvio.com.br/api/v2/me/shipment/calculate' => Http::response([
            ['id' => 2, 'name' => 'SEDEX', 'price' => '40.00', 'custom_price' => '35.50', 'delivery_time' => 3, 'custom_delivery_time' => 3, 'custom_delivery_range' => ['min' => 2, 'max' => 3], 'company' => ['name' => 'Correios']],
            ['id' => 1, 'name' => 'PAC', 'price' => '20.00', 'custom_price' => '18.90', 'delivery_time' => 8, 'custom_delivery_time' => 8, 'custom_delivery_range' => ['min' => 6, 'max' => 8], 'company' => ['name' => 'Correios']],
            ['id' => 3, 'name' => '.Package', 'error' => 'Serviço indisponível', 'company' => ['name' => 'Jadlog']],
        ])]);
    }

    private function product(array $over = []): Product
    {
        return Product::create($over + ['company_id' => $this->company->id, 'name' => 'Caneca', 'sku' => 'C1', 'cost' => 10, 'sale_price' => 50, 'stock_quantity' => 5]);
    }

    public function test_connect_returns_authorization_url_with_state(): void
    {
        $url = $this->withToken($this->token)->postJson('/api/v1/shipping/melhorenvio/connect')->assertOk()->json('url');

        $this->assertStringStartsWith('https://sandbox.melhorenvio.com.br/oauth/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('app-id', $q['client_id']);
        $this->assertSame('shipping-calculate', $q['scope']);
        $this->assertSame('code', $q['response_type']);
        $this->assertNotEmpty($q['state']);
    }

    public function test_free_plan_cannot_connect(): void
    {
        $this->company->update(['plan' => Company::PLAN_FREE]);

        $this->withToken($this->token)->postJson('/api/v1/shipping/melhorenvio/connect')->assertForbidden();
    }

    public function test_callback_stores_tokens_and_state_is_single_use(): void
    {
        $url = $this->withToken($this->token)->postJson('/api/v1/shipping/melhorenvio/connect')->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        Http::fake(['sandbox.melhorenvio.com.br/oauth/token' => Http::response(['access_token' => 'novo-acesso', 'refresh_token' => 'novo-refresh', 'expires_in' => 2592000])]);

        $this->get('/api/v1/melhorenvio/callback?code=abc&state='.$q['state'])
            ->assertRedirect('https://app.exemplo.com.br/integrations?melhorenvio=conectado#melhorenvio');

        $account = ShippingAccount::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('novo-acesso', $account->access_token);
        $this->assertNotSame('novo-acesso', $account->getRawOriginal('access_token'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/oauth/token') && $r['grant_type'] === 'authorization_code' && $r['code'] === 'abc' && $r['client_secret'] === 'app-secret');

        $this->get('/api/v1/melhorenvio/callback?code=abc&state='.$q['state'])->assertRedirectContains('melhorenvio=erro');
    }

    public function test_status_never_leaks_tokens(): void
    {
        $this->connect();

        $this->withToken($this->token)->getJson('/api/v1/shipping/melhorenvio')->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonMissingPath('account.access_token')
            ->assertJsonMissingPath('account.refresh_token');
    }

    public function test_rules_are_validated_and_saved(): void
    {
        $this->withToken($this->token)->putJson('/api/v1/shipping/melhorenvio/rules', $this->rules(['origin_cep' => '123']))->assertStatus(422);
        $this->withToken($this->token)->putJson('/api/v1/shipping/melhorenvio/rules', $this->rules(['origin_cep' => null]))->assertStatus(422);

        $this->withToken($this->token)->putJson('/api/v1/shipping/melhorenvio/rules', $this->rules())->assertOk()
            ->assertJsonPath('origin_cep', '01001000')->assertJsonPath('enabled', true);
    }

    public function test_public_quote_returns_sorted_options_with_handling_days(): void
    {
        $this->connect();
        $this->company->setting()->create(['shipping_rules' => $this->rules()]);
        $this->fakeCalculate();
        $product = $this->product(['ship_weight_g' => 500, 'ship_length_cm' => 30, 'ship_width_cm' => 20, 'ship_height_cm' => 8]);

        $res = $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', ['cep' => '20040-020', 'items' => [['product_id' => $product->id, 'quantity' => 2]]])->assertOk();

        $this->assertSame(['PAC', 'SEDEX'], array_column($res->json('options'), 'name'));
        $this->assertSame(18.9, $res->json('options.0.price'));
        $this->assertSame(10, $res->json('options.0.days'));
        $this->assertSame(8, $res->json('options.0.days_min'));

        Http::assertSent(function ($r) use ($product) {
            $p = $r['products'][0];

            return $r->hasHeader('Authorization', 'Bearer tok-loja')
                && str_contains($r->header('User-Agent')[0], '(')
                && $r['from']['postal_code'] === '01001000' && $r['to']['postal_code'] === '20040020'
                && $p['id'] === 'p'.$product->id && $p['weight'] == 0.5 && $p['length'] == 30 && $p['quantity'] === 2 && $p['insurance_value'] == 50;
        });
    }

    public function test_product_without_dimensions_uses_store_defaults_and_minimums(): void
    {
        $this->connect();
        $this->company->setting()->create(['shipping_rules' => $this->rules(['length_cm' => 5, 'width_cm' => 5, 'height_cm' => 1])]);
        $this->fakeCalculate();
        $product = $this->product();

        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', ['cep' => '20040020', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'calculate') && $r['products'][0]['weight'] == 0.3 && $r['products'][0]['length'] == 16 && $r['products'][0]['width'] == 11 && $r['products'][0]['height'] == 2);
    }

    public function test_quote_is_cached_for_same_cep_and_items(): void
    {
        $this->connect();
        $this->company->setting()->create(['shipping_rules' => $this->rules()]);
        $this->fakeCalculate();
        $product = $this->product();
        Cache::flush();
        $body = ['cep' => '20040020', 'items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', $body)->assertOk();
        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', $body)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_public_quote_is_hidden_when_shipping_is_off_or_not_connected(): void
    {
        $product = $this->product();
        $body = ['cep' => '20040020', 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $this->company->setting()->create(['shipping_rules' => $this->rules()]);

        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', $body)->assertNotFound();

        $this->connect();
        $this->company->setting->update(['shipping_rules' => $this->rules(['enabled' => false])]);
        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', $body)->assertNotFound();
    }

    public function test_other_stores_products_are_not_quoted(): void
    {
        $this->connect();
        $this->company->setting()->create(['shipping_rules' => $this->rules()]);
        $this->fakeCalculate();
        $other = Company::create(['name' => 'Outra', 'slug' => 'outra', 'plan' => Company::PLAN_PRO]);
        $foreign = Product::create(['company_id' => $other->id, 'name' => 'Alheio', 'sku' => 'X', 'cost' => 1, 'sale_price' => 5, 'stock_quantity' => 1]);

        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', ['cep' => '20040020', 'items' => [['product_id' => $foreign->id, 'quantity' => 1]]])
            ->assertStatus(422);
    }

    public function test_rejected_token_marks_account_for_reconnection(): void
    {
        $this->connect();
        $this->company->setting()->create(['shipping_rules' => $this->rules()]);
        Http::fake(['sandbox.melhorenvio.com.br/api/v2/me/shipment/calculate' => Http::response(['message' => 'Unauthenticated.'], 401)]);
        $product = $this->product();

        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', ['cep' => '20040020', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertStatus(422);

        $this->assertNotNull(ShippingAccount::first()->revoked_at);
        $this->withToken($this->token)->getJson('/api/v1/shipping/melhorenvio')->assertJsonPath('connected', false)->assertJsonPath('account.revoked', true);
    }

    public function test_expiring_access_is_refreshed_before_quoting(): void
    {
        ShippingAccount::create(['company_id' => $this->company->id, 'access_token' => 'velho', 'refresh_token' => 'ref-velho', 'expires_at' => now()->addDays(2), 'connected_at' => now()]);
        $this->company->setting()->create(['shipping_rules' => $this->rules()]);
        Http::fake([
            'sandbox.melhorenvio.com.br/oauth/token' => Http::response(['access_token' => 'renovado', 'refresh_token' => 'ref-novo', 'expires_in' => 2592000]),
            'sandbox.melhorenvio.com.br/api/v2/me/shipment/calculate' => Http::response([]),
        ]);
        $product = $this->product();

        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', ['cep' => '20040020', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'calculate') && $r->hasHeader('Authorization', 'Bearer renovado'));
        $this->assertSame('ref-novo', ShippingAccount::first()->refresh_token);
    }

    public function test_shows_why_when_every_carrier_refuses(): void
    {
        $this->connect();
        $this->company->setting()->create(['shipping_rules' => $this->rules()]);
        Http::fake(['sandbox.melhorenvio.com.br/api/v2/me/shipment/calculate' => Http::response([
            ['id' => 1, 'name' => 'PAC', 'error' => 'Dimensões excedem o limite', 'company' => ['name' => 'Correios']],
            ['id' => 3, 'name' => '.Package', 'error' => 'Serviço indisponível para o trecho', 'company' => ['name' => 'Jadlog']],
        ])]);
        $product = $this->product();
        $body = ['cep' => '62740000', 'items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', $body)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sem envio para este CEP. Correios PAC: Dimensões excedem o limite · Jadlog .Package: Serviço indisponível para o trecho');

        // Nada vazio fica em cache: a próxima tentativa consulta de novo
        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', $body)->assertStatus(422);
        Http::assertSentCount(2);
    }

    public function test_shows_api_message_when_response_is_not_a_list(): void
    {
        $this->connect();
        $this->company->setting()->create(['shipping_rules' => $this->rules()]);
        Http::fake(['sandbox.melhorenvio.com.br/api/v2/me/shipment/calculate' => Http::response(['message' => 'Complete seu cadastro para cotar.'])]);
        $product = $this->product();

        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', ['cep' => '20040020', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O Melhor Envio não devolveu as opções de envio: Complete seu cadastro para cotar.');
    }

    public function test_validation_error_from_api_is_shown(): void
    {
        $this->connect();
        $this->company->setting()->create(['shipping_rules' => $this->rules()]);
        Http::fake(['sandbox.melhorenvio.com.br/api/v2/me/shipment/calculate' => Http::response(['message' => 'The given data was invalid.', 'errors' => ['to.postal_code' => ['CEP de destino inválido.']]], 422)]);
        $product = $this->product();

        $this->postJson('/api/v1/public/catalog/token-frete/shipping-quote', ['cep' => '99999999', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O Melhor Envio não conseguiu calcular: CEP de destino inválido.');
    }

    public function test_catalog_flag_follows_availability(): void
    {
        $this->company->setting()->create(['shipping_rules' => $this->rules()]);
        $this->getJson('/api/v1/public/catalog/token-frete')->assertOk()->assertJsonPath('shipping_quote', false);

        $this->connect();
        Cache::flush();
        $this->getJson('/api/v1/public/catalog/token-frete')->assertOk()->assertJsonPath('shipping_quote', true);
    }

    public function test_product_shipping_dimensions_are_saved(): void
    {
        $res = $this->withToken($this->token)->postJson('/api/v1/products', ['name' => 'Vaso', 'cost' => 5, 'sale_price' => 20, 'ship_weight_g' => 450, 'ship_length_cm' => 25, 'ship_width_cm' => 20, 'ship_height_cm' => 12])
            ->assertCreated()->assertJsonPath('ship_weight_g', 450)->assertJsonPath('ship_height_cm', 12);

        $this->withToken($this->token)->putJson('/api/v1/products/'.$res->json('id'), ['name' => 'Vaso', 'cost' => 5, 'ship_weight_g' => 0])->assertStatus(422)->assertJsonValidationErrors('ship_weight_g');
    }
}
