<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentAccount;
use App\Models\PaymentCharge;
use App\Models\Quote;
use App\Models\User;
use App\Services\PagBankAccounts;
use App\Services\Payments\GatewayStatus;
use App\Services\Payments\PagBankGateway;
use App\Services\PixCharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** PagBank como gateway (API simulada): conexão da conta, Pix, link, webhook assinado, estorno e troca de gateway. */
class PagBankTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    private Quote $quote;

    private Customer $customer;

    /** Pedidos simulados no PagBank: ORDE_... => pedido */
    private array $orders = [];

    /** Links simulados: CHEC_... => checkout */
    private array $checkouts = [];

    /** @var list<string> */
    private array $calls = [];

    private int $next = 1;

    /** Chave pública dos avisos que o PagBank simulado devolve */
    private ?string $publicKey = null;

    /** Simula o acesso da loja recusado (401) em toda chamada */
    private bool $unauthorized = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.pagbank.sandbox' => true,
            'services.pagbank.client_id' => 'client-123',
            'services.pagbank.client_secret' => 'secret-456',
            'services.pagbank.platform_token' => null,
            'services.pagbank.redirect_uri' => 'https://api.exemplo.test/api/v1/pagbank/callback',
            'services.pagbank.webhook_url' => 'https://api.exemplo.com.br/api/v1/webhooks/pagbank',
            'services.mercadopago.test_access_token' => null,
            'services.frontend_url' => 'https://app.exemplo.test',
        ]);
        Cache::flush();
        $this->company = Company::create(['name' => 'Loja PagBank', 'slug' => 'loja-pagbank', 'plan' => Company::PLAN_PRO]);
        $owner = User::factory()->create(['company_id' => $this->company->id]);
        $this->token = $owner->createToken('api')->plainTextToken;
        PaymentAccount::create([
            'company_id' => $this->company->id, 'provider' => 'pagbank', 'provider_user_id' => 'ACCO_LOJA', 'access_token' => 'tok-loja',
            'refresh_token' => 'ref-loja', 'expires_at' => now()->addYear(), 'connected_at' => now(),
        ]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Ana Lima', 'phone' => '(21) 99999-0000', 'email' => 'ana@cliente.test', 'tax_id' => '12345678909']);
        $this->quote = Quote::forceCreate([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'name' => 'Topo de bolo', 'status' => Quote::STATUS_APPROVED,
            'quantity' => 1, 'final_price' => 150, 'amount_paid' => 0, 'print_time_minutes' => 0, 'material_weight_g' => 0, 'approved_at' => now(),
        ]);
        $this->fakePagBank();
    }

    private function fakePagBank(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            $this->calls[] = $request->method().' '.parse_url($url, PHP_URL_PATH);
            if ($this->unauthorized || str_starts_with((string) $request->header('Authorization')[0] ?? '', 'Bearer tok-recusado')) {
                return Http::response(['error_messages' => [['code' => 'UNAUTHORIZED', 'description' => 'invalid token']]], 401);
            }
            if (str_contains($url, '/public-keys')) {
                return Http::response(['public_key' => $this->publicKey]);
            }
            if (str_ends_with($url, '/oauth2/token')) {
                return Http::response(['token_type' => 'bearer', 'access_token' => 'novo-acesso', 'refresh_token' => 'novo-refresh', 'expires_in' => '31536000', 'account_id' => 'ACCO_NOVA']);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/orders')) {
                // Formato visto no sandbox: QR em qr_codes, sem charges até o pagamento
                $id = 'ORDE_'.$this->next++;
                $this->orders[$id] = [
                    'id' => $id, 'reference_id' => $request['reference_id'],
                    'qr_codes' => [['id' => 'QRCO_1', 'expiration_date' => $request['qr_codes'][0]['expiration_date'], 'amount' => $request['qr_codes'][0]['amount'], 'text' => "00020101PIX{$id}"]],
                ];

                return Http::response($this->orders[$id], 201);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/checkouts')) {
                $id = 'CHEC_'.$this->next++;
                $this->checkouts[$id] = ['id' => $id, 'reference_id' => $request['reference_id'], 'status' => 'ACTIVE', 'links' => [
                    ['rel' => 'PAY', 'href' => "https://pagamento.pagbank.test/pagamento?code={$id}", 'method' => 'GET'],
                ]];

                return Http::response($this->checkouts[$id]);
            }
            if (preg_match('#/checkouts/(CHEC_\d+)/inactivate$#', $url, $m)) {
                $this->checkouts[$m[1]]['status'] = 'INACTIVE';

                return Http::response($this->checkouts[$m[1]]);
            }
            if (preg_match('#/checkouts/(CHEC_\d+)$#', $url, $m)) {
                return Http::response($this->checkouts[$m[1]]);
            }
            if (preg_match('#/orders/(ORDE_\d+)$#', $url, $m)) {
                return Http::response($this->orders[$m[1]]);
            }
            if (preg_match('#/charges/(CHAR_\d+)/cancel$#', $url, $m)) {
                foreach ($this->orders as $id => $order) {
                    if (($order['charges'][0]['id'] ?? null) === $m[1]) {
                        $this->orders[$id]['charges'][0]['status'] = 'CANCELED';
                        $this->orders[$id]['charges'][0]['amount']['summary']['refunded'] = $request['amount']['value'];
                    }
                }

                return Http::response(['id' => $m[1], 'status' => 'CANCELED'], 201);
            }

            return Http::response([], 404);
        });
    }

    /** Pago: o PagBank cria a cobrança CHAR_... no pedido */
    private function payOrder(string $id, string $type = 'PIX'): void
    {
        $value = $this->orders[$id]['qr_codes'][0]['amount']['value'];
        $this->orders[$id]['charges'] = [[
            'id' => 'CHAR_'.substr($id, 5), 'status' => 'PAID',
            'amount' => ['value' => $value, 'currency' => 'BRL', 'summary' => ['total' => $value, 'paid' => $value, 'refunded' => 0]],
            'payment_method' => ['type' => $type],
        ]];
    }

    private function charge(float $amount, string $method = 'pix')
    {
        return $this->withToken($this->token)->postJson("/api/v1/quotes/{$this->quote->id}/pix", ['amount' => $amount, 'method' => $method]);
    }

    public function test_pix_needs_the_customer_document(): void
    {
        $this->customer->update(['tax_id' => null]);

        $this->charge(150)->assertStatus(422)
            ->assertJsonPath('message', 'Para gerar Pix pelo PagBank, cadastre o CPF ou CNPJ do cliente. Ou gere o link de pagamento (cartão ou Pix), que não pede o documento.');
        $this->assertSame(0, PaymentCharge::count());
        $this->assertSame([], $this->calls);
    }

    public function test_creates_a_pix_order_in_cents_with_the_customer(): void
    {
        $this->charge(150)->assertCreated()->assertJsonPath('qr_code', '00020101PIXORDE_1')->assertJsonPath('method', 'pix');

        $charge = PaymentCharge::first();
        $this->assertSame('pagbank', $charge->provider);
        $this->assertSame('ORDE_1', $charge->external_id);
        $this->assertNull($charge->external_payment_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'sandbox.api.pagseguro.com/orders')
            && $r->hasHeader('Authorization', 'Bearer tok-loja')
            && $r['customer']['tax_id'] === '12345678909'
            && $r['customer']['phones'][0]['area'] === '21'
            && $r['qr_codes'][0]['amount']['value'] === 15000
            && ! isset($r['charges'])
            && $r['notification_urls'] === ['https://api.exemplo.com.br/api/v1/webhooks/pagbank']);

        // Pago: a cobrança CHAR_... que aparece no pedido fica guardada (é por ela que o estorno segue)
        $this->payOrder('ORDE_1');
        app(PixCharges::class)->sync($charge, force: true);
        $this->assertSame('CHAR_1', $charge->fresh()->external_payment_id);
    }

    public function test_local_webhook_address_is_not_sent(): void
    {
        // O PagBank recusa o pedido inteiro com endereço local ("invalid notification url")
        config(['services.pagbank.webhook_url' => 'http://localhost/api/v1/webhooks/pagbank']);

        $this->charge(150)->assertCreated();
        $this->charge(150, 'link')->assertCreated();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/orders') && ! isset($r['notification_urls']));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/checkouts') && ! isset($r['notification_urls']) && ! isset($r['payment_notification_urls']));
    }

    public function test_paid_pix_enters_the_sale_and_inactivates_the_link(): void
    {
        $pixId = $this->charge(150)->json('id');
        $linkId = $this->charge(150, 'link')->assertCreated()->assertJsonPath('checkout_url', 'https://pagamento.pagbank.test/pagamento?code=CHEC_2')->json('id');

        $this->payOrder('ORDE_1');
        app(PixCharges::class)->sync(PaymentCharge::find($pixId), force: true);

        $payment = $this->quote->payments()->latest('id')->first();
        $this->assertSame('150.00', (string) $this->quote->fresh()->amount_paid);
        $this->assertSame('pagbank', $payment->source);
        $this->assertSame(Quote::PAYMENT_PIX, $payment->method);
        $this->assertSame("Pix pelo PagBank (#{$pixId})", $payment->note);
        $this->assertSame(PaymentCharge::STATUS_CANCELED, PaymentCharge::find($linkId)->status);
        $this->assertSame('INACTIVE', $this->checkouts['CHEC_2']['status']);
    }

    public function test_paid_link_arrives_by_webhook_as_an_order(): void
    {
        $pixId = $this->charge(150)->json('id');
        $linkId = $this->charge(150, 'link')->json('id');
        $reference = "mct-{$this->quote->id}-{$linkId}";

        // O link pago vira um pedido novo no PagBank, com o reference_id que o sistema mandou
        $this->orders['ORDE_9'] = [
            'id' => 'ORDE_9', 'reference_id' => $reference,
            'charges' => [['id' => 'CHAR_9', 'reference_id' => $reference, 'status' => 'PAID', 'amount' => ['value' => 15000, 'summary' => ['total' => 15000, 'paid' => 15000, 'refunded' => 0]], 'payment_method' => ['type' => 'CREDIT_CARD']]],
        ];
        $this->postJson('/api/v1/webhooks/pagbank', ['id' => 'ORDE_9', 'reference_id' => $reference, 'charges' => [['status' => 'PAID']]])->assertOk();

        $link = PaymentCharge::find($linkId);
        $this->assertSame('ORDE_9', $link->meta['order_id']);
        $this->assertSame(PaymentCharge::STATUS_PAID, $link->status);
        $this->assertSame('CHAR_9', $link->external_payment_id);
        $this->assertSame(Quote::PAYMENT_CREDIT, $this->quote->payments()->latest('id')->first()->method);
        $this->assertSame("Link do PagBank (#{$linkId})", $this->quote->payments()->latest('id')->first()->note);
        // O Pix irmão não tem cancelamento no PagBank: fica cancelado só no sistema (sem chamada à API)
        $this->assertSame(PaymentCharge::STATUS_CANCELED, PaymentCharge::find($pixId)->status);
        $this->assertNotContains('POST /orders/ORDE_1/cancel', $this->calls);

        // Estorno do link segue pelo pedido gerado (CHAR_9), com o valor todo em centavos
        $this->withToken($this->token)->postJson("/api/v1/quotes/{$this->quote->id}/pix/{$linkId}/refund")->assertOk()->assertJsonPath('status', 'refunded');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/charges/CHAR_9/cancel') && $r['amount']['value'] === 15000);
        $this->assertSame('0.00', (string) $this->quote->fresh()->amount_paid);
    }

    public function test_states_are_translated(): void
    {
        $order = fn (string $status, array $summary = [], ?string $expires = null, string $type = 'PIX') => PagBankGateway::translateOrder(['charges' => [[
            'id' => 'CHAR_1', 'status' => $status, 'amount' => ['value' => 1000, 'summary' => $summary + ['total' => 1000]],
            'payment_method' => ['type' => $type, 'pix' => ['expiration_date' => $expires]],
        ]]]);

        $this->assertSame(GatewayStatus::PENDING, $order('WAITING')->state);
        $this->assertSame(GatewayStatus::PAID, $order('PAID', ['paid' => 1000])->state);
        $this->assertSame(GatewayStatus::REFUNDED, $order('CANCELED', ['paid' => 1000, 'refunded' => 1000])->state);
        $this->assertSame(GatewayStatus::EXPIRED, $order('CANCELED', [], now()->subHour()->toIso8601String())->state);
        $this->assertSame(GatewayStatus::CANCELED, $order('CANCELED', [], now()->addHour()->toIso8601String())->state);
        $this->assertSame(GatewayStatus::FAILED, $order('DECLINED')->state);
        $this->assertSame(Quote::PAYMENT_CREDIT, $order('PAID', [], null, 'CREDIT_CARD')->paymentMethod);
        $this->assertSame(GatewayStatus::EXPIRED, PagBankGateway::translateCheckout(['status' => 'EXPIRED'])->state);
        $this->assertSame(GatewayStatus::CANCELED, PagBankGateway::translateCheckout(['status' => 'INACTIVE'])->state);
        $this->assertSame('PIX-DA-LISTA', PagBankGateway::qrText(['qr_codes' => [['text' => 'PIX-DA-LISTA']]]));
    }

    public function test_webhook_signature_is_checked_with_the_platform_public_key(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($key)['key'];
        $der = base64_encode(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem)));
        config(['services.pagbank.platform_token' => 'tok-plataforma']);
        $this->publicKey = $der;

        $pixId = $this->charge(150)->json('id');
        $this->payOrder('ORDE_1');
        $raw = json_encode(['id' => 'ORDE_1', 'charges' => [['status' => 'PAID']]]);
        openssl_sign($raw, $signature, $key, OPENSSL_ALGO_SHA256);

        $this->call('POST', '/api/v1/webhooks/pagbank', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYLOAD_SIGNATURE' => 'outra-assinatura,'.base64_encode('lixo')], $raw)->assertStatus(401);
        $this->assertSame(PaymentCharge::STATUS_PENDING, PaymentCharge::find($pixId)->status);

        $this->call('POST', '/api/v1/webhooks/pagbank', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYLOAD_SIGNATURE' => base64_encode('velha').','.base64_encode($signature)], $raw)->assertOk();
        $this->assertSame(PaymentCharge::STATUS_PAID, PaymentCharge::find($pixId)->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/public-keys') && $r->hasHeader('Authorization', 'Bearer tok-plataforma'));
    }

    public function test_connect_replaces_the_other_gateway(): void
    {
        config(['services.pagbank.platform_token' => 'tok-plataforma']);
        PaymentAccount::where('company_id', $this->company->id)->update(['provider' => 'mercadopago', 'provider_user_id' => '123']);

        $this->withToken($this->token)->getJson('/api/v1/payments/pagbank')->assertOk()
            ->assertJsonPath('connected', false)->assertJsonPath('other_gateway', 'mercadopago')->assertJsonPath('available', true);
        $url = $this->withToken($this->token)->postJson('/api/v1/payments/pagbank/connect')->assertOk()->json('url');
        $this->assertStringStartsWith('https://connect.sandbox.pagbank.com.br/oauth2/authorize?', $url);
        $this->assertStringContainsString('scope=payments.read+payments.create+payments.refund', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->get('/api/v1/pagbank/callback?code=CODE1&state='.$query['state'])
            ->assertRedirect('https://app.exemplo.test/integrations?pagbank=conectado#pagbank');

        $account = PaymentAccount::where('company_id', $this->company->id)->sole();
        $this->assertSame('pagbank', $account->provider);
        $this->assertSame('ACCO_NOVA', $account->provider_user_id);
        $this->assertSame('novo-acesso', $account->access_token);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/oauth2/token') && $r->hasHeader('X_CLIENT_ID', 'client-123')
            && $r->hasHeader('Authorization', 'Bearer tok-plataforma') && $r['code'] === 'CODE1');

        // O card do Mercado Pago passa a mostrar que a loja usa outro gateway
        $this->withToken($this->token)->getJson('/api/v1/payments/mercadopago')->assertJsonPath('connected', false)->assertJsonPath('other_gateway', 'pagbank');
        $this->assertSame('pagbank', app(\App\Services\Payments\PaymentGateways::class)->for($this->company)->key());
    }

    public function test_store_connects_by_pasting_its_own_token(): void
    {
        config(['services.pagbank.client_id' => null, 'services.pagbank.client_secret' => null]);
        PaymentAccount::where('company_id', $this->company->id)->update(['provider' => 'mercadopago', 'provider_user_id' => '123']);

        $this->withToken($this->token)->getJson('/api/v1/payments/pagbank')->assertOk()
            ->assertJsonPath('available', true)->assertJsonPath('connect_available', false)->assertJsonPath('other_gateway', 'mercadopago');
        $this->withToken($this->token)->postJson('/api/v1/payments/pagbank/connect')->assertStatus(422);

        $this->withToken($this->token)->postJson('/api/v1/payments/pagbank/token', ['token' => 'curto'])
            ->assertStatus(422)->assertJsonPath('message', 'Esse token não parece completo. Copie o token inteiro no painel do PagBank.');
        $this->withToken($this->token)->postJson('/api/v1/payments/pagbank/token', ['token' => 'tok-recusado-0123456789abcdef'])
            ->assertStatus(422)->assertJsonPath('message', 'O PagBank recusou o token. Confira se ele é da conta de testes (sandbox) e foi copiado inteiro.');
        $this->assertSame('mercadopago', PaymentAccount::where('company_id', $this->company->id)->value('provider'));

        $this->withToken($this->token)->postJson('/api/v1/payments/pagbank/token', ['token' => '  tok-colado-0123456789abcdef  '])->assertOk()
            ->assertJsonPath('connected', true)->assertJsonPath('account.mode', 'token')->assertJsonPath('other_gateway', null);
        $account = PaymentAccount::where('company_id', $this->company->id)->sole();
        $this->assertSame('pagbank', $account->provider);
        $this->assertSame('tok-colado-0123456789abcdef', $account->access_token);
        $this->assertNull($account->refresh_token);
        $this->assertNull($account->expires_at);

        // Cobra com o token colado, sem renovar
        $this->charge(150)->assertCreated();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/orders') && $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer tok-colado-0123456789abcdef'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/oauth2/'));

        // O mesmo token em outra loja é recusado
        $other = Company::create(['name' => 'Outra', 'slug' => 'outra-loja', 'plan' => Company::PLAN_PRO]);
        $otherToken = User::factory()->create(['company_id' => $other->id])->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->postJson('/api/v1/payments/pagbank/token', ['token' => 'tok-colado-0123456789abcdef'])
            ->assertStatus(422)->assertJsonPath('message', 'Este token do PagBank já está conectado a outra loja do Meu Custo Total.');
    }

    public function test_webhook_is_checked_with_the_store_token(): void
    {
        $pixId = $this->charge(150)->json('id');
        $this->payOrder('ORDE_1');
        $raw = json_encode(['id' => 'ORDE_1', 'charges' => [['status' => 'PAID']]]);
        $post = fn (array $headers) => $this->call('POST', '/api/v1/webhooks/pagbank', [], [], [], ['CONTENT_TYPE' => 'application/json'] + $headers, $raw);

        $post(['HTTP_X_AUTHENTICITY_TOKEN' => hash('sha256', 'outro-token-'.$raw)])->assertStatus(401);
        $this->app['env'] = 'production';
        $post([])->assertStatus(401);
        $this->assertSame(PaymentCharge::STATUS_PENDING, PaymentCharge::find($pixId)->status);

        $post(['HTTP_X_AUTHENTICITY_TOKEN' => strtoupper(hash('sha256', 'tok-loja-'.$raw))])->assertOk();
        $this->assertSame(PaymentCharge::STATUS_PAID, PaymentCharge::find($pixId)->status);
    }

    public function test_customer_document_is_validated_and_stored_as_digits(): void
    {
        $this->withToken($this->token)->putJson("/api/v1/customers/{$this->customer->id}", ['name' => 'Ana Lima', 'tax_id' => '123.456.789-0'])
            ->assertStatus(422)->assertJsonPath('errors.tax_id.0', 'CPF precisa de 11 dígitos e CNPJ de 14.');
        $this->withToken($this->token)->putJson("/api/v1/customers/{$this->customer->id}", ['name' => 'Ana Lima', 'tax_id' => '12.345.678/0001-90'])->assertOk();
        $this->assertSame('12345678000190', $this->customer->fresh()->tax_id);
    }

    public function test_revoked_access_marks_the_account_to_reconnect(): void
    {
        $this->unauthorized = true;

        $this->charge(150)->assertStatus(422)->assertJsonPath('message', 'O PagBank não gerou a cobrança agora. Tente de novo em instantes.');
        $this->assertNotNull(PaymentAccount::first()->revoked_at);
        $this->assertSame('Conecte a conta do PagBank em Integrações para cobrar online.', app(PixCharges::class)->refusal($this->quote->fresh()));
    }
}
