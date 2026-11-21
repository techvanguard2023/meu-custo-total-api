<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentCharge;
use App\Models\Quote;
use App\Models\User;
use App\Services\PixCharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobrança online (hoje pelo Mercado Pago, API simulada): gerar Pix e link, confirmar o pagamento (baixa na venda),
 * cancelar a outra cobrança aberta, estornar e receber o aviso do webhook. Garante que separar o gateway numa
 * camada própria não muda nada para o lojista.
 */
class OnlinePaymentChargeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    private Quote $quote;

    /** Pedidos simulados no Mercado Pago: id => resposta do GET /v1/orders/{id} */
    private array $orders = [];

    private int $next = 1;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mercadopago.test_access_token' => 'TEST-token-loja',
            'services.mercadopago.webhook_secret' => 'segredo-webhook',
        ]);
        $this->company = Company::create(['name' => 'Loja Pix', 'slug' => 'loja-pix', 'plan' => Company::PLAN_PRO]);
        $owner = User::factory()->create(['company_id' => $this->company->id]);
        $this->token = $owner->createToken('api')->plainTextToken;
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Ana', 'phone' => '21999990000', 'email' => 'ana@cliente.test']);
        $this->quote = Quote::forceCreate([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'name' => 'Topo de bolo', 'status' => Quote::STATUS_APPROVED,
            'quantity' => 1, 'final_price' => 100, 'amount_paid' => 0, 'print_time_minutes' => 0, 'material_weight_g' => 0, 'approved_at' => now(),
        ]);
        $this->fakeMercadoPago();
    }

    private function fakeMercadoPago(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_ends_with($url, '/v1/orders')) {
                $id = 'ORD'.$this->next++;
                $isLink = ($request['processing_mode'] ?? '') === 'manual';
                $this->orders[$id] = [
                    'id' => $id, 'status' => 'action_required', 'status_detail' => 'waiting_payment',
                    'checkout_url' => $isLink ? "https://mp.test/checkout/{$id}" : null,
                    'transactions' => ['payments' => [[
                        'id' => "PAY{$id}",
                        'payment_method' => $isLink ? [] : ['id' => 'pix', 'type' => 'bank_transfer', 'qr_code' => "00020126PIX{$id}", 'ticket_url' => "https://mp.test/pix/{$id}"],
                    ]]],
                ];

                return Http::response($this->orders[$id], 201);
            }
            if (preg_match('#/v1/orders/(ORD\d+)/cancel$#', $url, $m)) {
                $this->orders[$m[1]]['status'] = 'canceled';

                return Http::response($this->orders[$m[1]]);
            }
            if (preg_match('#/v1/orders/(ORD\d+)/refund$#', $url, $m)) {
                $this->orders[$m[1]]['status'] = 'refunded';

                return Http::response($this->orders[$m[1]]);
            }
            if (preg_match('#/v1/orders/(ORD\d+)$#', $url, $m)) {
                return Http::response($this->orders[$m[1]]);
            }

            return Http::response([], 404);
        });
    }

    private function pay(string $orderId, string $type = 'bank_transfer', string $id = 'pix'): void
    {
        $this->orders[$orderId]['status'] = 'processed';
        $this->orders[$orderId]['status_detail'] = 'accredited';
        $this->orders[$orderId]['transactions']['payments'][0]['payment_method'] = ['id' => $id, 'type' => $type];
    }

    private function charge(float $amount, string $method = 'pix')
    {
        return $this->withToken($this->token)->postJson("/api/v1/quotes/{$this->quote->id}/pix", ['amount' => $amount, 'method' => $method]);
    }

    public function test_creates_a_pix_and_a_link(): void
    {
        $this->charge(40)->assertCreated()
            ->assertJsonPath('method', 'pix')->assertJsonPath('amount', 40)->assertJsonPath('open', true)
            ->assertJsonPath('qr_code', '00020126PIXORD1');
        $this->charge(60, 'link')->assertCreated()->assertJsonPath('checkout_url', 'https://mp.test/checkout/ORD2');

        $pix = PaymentCharge::where('external_id', 'ORD1')->first();
        $this->assertSame('mercadopago', $pix->provider);
        $this->assertSame('PAYORD1', $pix->external_payment_id);
        $this->assertSame('action_required/waiting_payment', $pix->provider_status);

        // Mesmo valor e método com um aberto: devolve o mesmo, sem criar outro no gateway
        $this->charge(40)->assertCreated()->assertJsonPath('id', $pix->id);
        $this->assertSame(2, PaymentCharge::count());
    }

    public function test_paid_pix_enters_the_sale_and_cancels_the_other_open_charge(): void
    {
        $pixId = $this->charge(100)->json('id');
        $linkId = $this->charge(100, 'link')->json('id');

        $this->pay('ORD1');
        // A tela só consulta o gateway de novo depois de 8 s
        $this->travel(10)->seconds();
        $this->withToken($this->token)->getJson("/api/v1/quotes/{$this->quote->id}/pix/{$pixId}")->assertOk()
            ->assertJsonPath('status', 'paid')->assertJsonPath('quote.amount_due', 0);

        $this->assertSame('100.00', (string) $this->quote->fresh()->amount_paid);
        $this->assertSame(Quote::PAYMENT_PIX, $this->quote->payments()->latest('id')->first()->method);
        $this->assertSame('Pix pelo Mercado Pago (#'.$pixId.')', $this->quote->payments()->latest('id')->first()->note);
        // O link mandado junto foi cancelado no gateway
        $this->assertSame(PaymentCharge::STATUS_CANCELED, PaymentCharge::find($linkId)->status);
        $this->assertSame('canceled', $this->orders['ORD2']['status']);
    }

    public function test_link_paid_by_card_records_credit(): void
    {
        $linkId = $this->charge(100, 'link')->json('id');
        $this->pay('ORD1', 'credit_card', 'master');

        app(PixCharges::class)->sync(PaymentCharge::find($linkId), force: true);

        $payment = $this->quote->payments()->latest('id')->first();
        $this->assertSame(Quote::PAYMENT_CREDIT, $payment->method);
        $this->assertSame('Link do Mercado Pago (#'.$linkId.')', $payment->note);
    }

    public function test_refund_takes_the_money_back_out(): void
    {
        $pixId = $this->charge(100)->json('id');
        $this->pay('ORD1');
        app(PixCharges::class)->sync(PaymentCharge::find($pixId), force: true);

        $this->withToken($this->token)->postJson("/api/v1/quotes/{$this->quote->id}/pix/{$pixId}/refund")->assertOk()->assertJsonPath('status', 'refunded');

        $this->assertSame('0.00', (string) $this->quote->fresh()->amount_paid);
        $this->assertSame('refunded', $this->orders['ORD1']['status']);
    }

    public function test_expired_and_failed_states(): void
    {
        $pixId = $this->charge(50)->json('id');
        $linkId = $this->charge(50, 'link')->json('id');

        // Cartão recusado no link não encerra o link (o cliente tenta de novo)
        $this->orders['ORD2']['status'] = 'failed';
        app(PixCharges::class)->sync(PaymentCharge::find($linkId), force: true);
        $this->assertSame(PaymentCharge::STATUS_PENDING, PaymentCharge::find($linkId)->status);

        $this->orders['ORD1']['status'] = 'expired';
        app(PixCharges::class)->sync(PaymentCharge::find($pixId), force: true);
        $this->assertSame(PaymentCharge::STATUS_EXPIRED, PaymentCharge::find($pixId)->status);
    }

    public function test_webhook_with_valid_signature_confirms_the_payment(): void
    {
        $pixId = $this->charge(100)->json('id');
        $this->pay('ORD1');

        $ts = (string) time();
        $signature = hash_hmac('sha256', "id:ORD1;request-id:req-1;ts:{$ts};", 'segredo-webhook');

        $this->postJson('/api/v1/webhooks/mercadopago?type=order&data.id=ORD1', ['type' => 'order', 'data' => ['id' => 'ORD1']], [
            'x-signature' => "ts={$ts},v1={$signature}", 'x-request-id' => 'req-1',
        ])->assertOk();
        $this->assertSame(PaymentCharge::STATUS_PAID, PaymentCharge::find($pixId)->status);

        $this->postJson('/api/v1/webhooks/mercadopago?type=order&data.id=ORD1', [], ['x-signature' => "ts={$ts},v1=errado", 'x-request-id' => 'req-1'])
            ->assertStatus(401);
    }

    public function test_store_without_gateway_is_refused(): void
    {
        config(['services.mercadopago.test_access_token' => null]);

        $this->charge(10)->assertStatus(422)->assertJsonPath('message', 'Conecte a conta do Mercado Pago em Integrações para cobrar online.');
    }
}
