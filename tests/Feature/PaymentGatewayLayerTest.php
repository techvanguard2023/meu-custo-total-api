<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentAccount;
use App\Models\PaymentCharge;
use App\Models\Quote;
use App\Models\User;
use App\Services\Payments\GatewayCharge;
use App\Services\Payments\GatewayStatus;
use App\Services\Payments\MercadoPagoGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGateways;
use App\Services\PixCharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;

/**
 * Camada de gateways: a tradução do Mercado Pago e a prova de que outro provedor encaixa sem mexer na regra de
 * cobrança (PixCharges) — com um gateway de mentira registrado só aqui.
 */
class PaymentGatewayLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mercado_pago_states_are_translated(): void
    {
        $t = fn (string $status, string $detail = '', array $pm = []) => MercadoPagoGateway::translate([
            'status' => $status, 'status_detail' => $detail, 'transactions' => ['payments' => [['id' => 'PAY1', 'payment_method' => $pm]]],
        ]);

        $this->assertSame(GatewayStatus::PAID, $t('processed', 'accredited')->state);
        $this->assertSame(GatewayStatus::REFUNDED, $t('processed', 'refunded')->state);
        $this->assertSame(GatewayStatus::REFUNDED, $t('charged_back')->state);
        $this->assertSame(GatewayStatus::EXPIRED, $t('expired')->state);
        $this->assertSame(GatewayStatus::EXPIRED, $t('canceled', 'expired')->state);
        $this->assertSame(GatewayStatus::CANCELED, $t('canceled')->state);
        $this->assertSame(GatewayStatus::FAILED, $t('failed')->state);
        $this->assertSame(GatewayStatus::PENDING, $t('action_required', 'waiting_payment')->state);

        $this->assertSame(Quote::PAYMENT_CREDIT, $t('processed', '', ['type' => 'credit_card'])->paymentMethod);
        $this->assertSame(Quote::PAYMENT_DEBIT, $t('processed', '', ['type' => 'debit_card'])->paymentMethod);
        $this->assertSame(Quote::PAYMENT_PIX, $t('processed', '', ['id' => 'pix', 'type' => 'bank_transfer'])->paymentMethod);
        $this->assertNull($t('processed')->paymentMethod);
        $this->assertSame('PAY1', $t('processed')->paymentId);
        $this->assertSame('processed/accredited', $t('processed', 'accredited')->providerStatus);
    }

    public function test_store_without_account_uses_the_default_gateway(): void
    {
        $company = Company::create(['name' => 'Loja', 'slug' => 'loja-gw', 'plan' => Company::PLAN_PRO]);

        $this->assertSame('mercadopago', app(PaymentGateways::class)->for($company)->key());
    }

    public function test_another_gateway_plugs_in_without_touching_the_charge_rules(): void
    {
        app(PaymentGateways::class)->extend('fakepay', FakePayGateway::class);
        FakePayGateway::$state = GatewayStatus::PENDING;

        $company = Company::create(['name' => 'Loja Fake', 'slug' => 'loja-fake', 'plan' => Company::PLAN_PRO]);
        PaymentAccount::create(['company_id' => $company->id, 'provider' => 'fakepay', 'provider_user_id' => 'u1', 'access_token' => 'x', 'connected_at' => now()]);
        $token = User::factory()->create(['company_id' => $company->id])->createToken('api')->plainTextToken;
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Ana', 'phone' => '21999990000']);
        $quote = Quote::forceCreate([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'name' => 'Caneca', 'status' => Quote::STATUS_APPROVED,
            'quantity' => 1, 'final_price' => 80, 'amount_paid' => 0, 'print_time_minutes' => 0, 'material_weight_g' => 0, 'approved_at' => now(),
        ]);

        $id = $this->withToken($token)->postJson("/api/v1/quotes/{$quote->id}/pix", ['amount' => 80])->assertCreated()
            ->assertJsonPath('qr_code', 'FAKE-PIX-80.00')->json('id');
        $this->assertSame('fakepay', PaymentCharge::find($id)->provider);

        FakePayGateway::$state = GatewayStatus::PAID;
        app(PixCharges::class)->sync(PaymentCharge::find($id), force: true);

        $payment = $quote->payments()->latest('id')->first();
        $this->assertSame('80.00', (string) $quote->fresh()->amount_paid);
        $this->assertSame('fakepay', $payment->source);
        $this->assertSame("Pix pelo Fake Pay (#{$id})", $payment->note);

        // Sem conta pronta, a recusa fala o nome do gateway da loja
        FakePayGateway::$ready = false;
        $other = Quote::forceCreate([
            'company_id' => $company->id, 'name' => 'Outra', 'status' => Quote::STATUS_APPROVED, 'quantity' => 1, 'final_price' => 10,
            'print_time_minutes' => 0, 'material_weight_g' => 0, 'approved_at' => now(),
        ]);
        $this->assertSame('Conecte a conta do Fake Pay em Integrações para cobrar online.', app(PixCharges::class)->refusal($other));
    }
}

/** Gateway de mentira: cobra sem rede e devolve a situação que o teste mandar. */
class FakePayGateway implements PaymentGateway
{
    public static string $state = GatewayStatus::PENDING;

    public static bool $ready = true;

    public function key(): string
    {
        return 'fakepay';
    }

    public function label(): string
    {
        return 'Fake Pay';
    }

    public function ready(Company $company): bool
    {
        return self::$ready;
    }

    public function createPix(Company $company, float $amount, string $reference, string $payerEmail, int $validityHours, string $idempotencyKey): GatewayCharge
    {
        return new GatewayCharge('FK-'.$reference, null, 'waiting', 'FAKE-PIX-'.number_format($amount, 2, '.', ''), null);
    }

    public function createLink(Company $company, float $amount, string $title, string $reference, ?string $returnUrl, string $idempotencyKey): GatewayCharge
    {
        return new GatewayCharge('FK-'.$reference, null, 'waiting', null, 'https://fake.test/'.$reference);
    }

    public function fetch(Company $company, string $externalId): GatewayStatus
    {
        return new GatewayStatus(self::$state, self::$state, 'FKPAY-'.$externalId, Quote::PAYMENT_PIX);
    }

    public function cancel(Company $company, string $externalId): void {}

    public function refund(Company $company, string $externalId): GatewayStatus
    {
        return new GatewayStatus(GatewayStatus::REFUNDED, 'refunded', 'FKPAY-'.$externalId);
    }

    public function handleError(?Company $company, int $quoteId, Throwable $e): void {}
}
