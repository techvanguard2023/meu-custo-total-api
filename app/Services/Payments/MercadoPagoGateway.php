<?php

namespace App\Services\Payments;

use App\Models\Company;
use App\Models\PaymentCharge;
use App\Models\Quote;
use App\Services\MercadoPago;
use App\Services\MercadoPagoAccounts;
use App\Services\MercadoPagoException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mercado Pago (API de Orders) como gateway: Pix (processamento automático) e link do Checkout Pro (cartão
 * parcelado ou Pix). A conexão da conta (OAuth) e o webhook continuam em MercadoPagoAccounts e
 * MercadoPagoWebhookController — são específicos de cada provedor.
 */
class MercadoPagoGateway implements PaymentGateway
{
    public function __construct(private MercadoPago $api, private MercadoPagoAccounts $accounts) {}

    public function key(): string
    {
        return 'mercadopago';
    }

    public function label(): string
    {
        return 'Mercado Pago';
    }

    public function ready(Company $company): bool
    {
        return $this->accounts->ready($company);
    }

    public function createPix(Company $company, float $amount, string $reference, Payer $payer, int $validityHours, string $idempotencyKey): GatewayCharge
    {
        $order = $this->api->createPixOrder($this->accounts->token($company), $amount, $reference, $payer->email, "PT{$validityHours}H", $idempotencyKey);
        $payment = $order['transactions']['payments'][0] ?? [];

        return new GatewayCharge(
            externalId: $order['id'] ?? null,
            externalPaymentId: $payment['id'] ?? null,
            providerStatus: self::providerStatus($order),
            qrCode: $payment['payment_method']['qr_code'] ?? null,
            url: $payment['payment_method']['ticket_url'] ?? null,
        );
    }

    public function createLink(Company $company, float $amount, string $title, string $reference, ?string $returnUrl, string $idempotencyKey): GatewayCharge
    {
        $order = $this->api->createCheckoutOrder($this->accounts->token($company), $amount, $title, $reference, $returnUrl, $idempotencyKey);
        $payment = $order['transactions']['payments'][0] ?? [];

        return new GatewayCharge(
            externalId: $order['id'] ?? null,
            externalPaymentId: $payment['id'] ?? null,
            providerStatus: self::providerStatus($order),
            qrCode: null,
            url: $order['checkout_url'] ?? null,
        );
    }

    public function fetch(Company $company, PaymentCharge $charge): GatewayStatus
    {
        return self::translate($this->api->getOrder($this->accounts->token($company), $charge->external_id));
    }

    public function cancel(Company $company, PaymentCharge $charge): void
    {
        $this->api->cancelOrder($this->accounts->token($company), $charge->external_id);
    }

    public function refund(Company $company, PaymentCharge $charge): GatewayStatus
    {
        $this->api->refundOrder($this->accounts->token($company), $charge->external_id);
        $status = $this->fetch($company, $charge);

        // O Mercado Pago aceitou o estorno; a consulta logo depois pode ainda não mostrar — vale como estornado
        return $status->state === GatewayStatus::REFUNDED ? $status : $status->withState(GatewayStatus::REFUNDED);
    }

    public function handleError(?Company $company, int $quoteId, Throwable $e): void
    {
        Log::warning("Mercado Pago (venda {$quoteId}): ".Str::limit($e->getMessage(), 300));
        // Acesso recusado (401): a loja revogou no Mercado Pago — marca para conectar de novo
        if ($company && $e instanceof MercadoPagoException && $e->getCode() === 401 && ($account = $this->accounts->account($company))) {
            $this->accounts->revokeByProviderUser($account->provider_user_id);
        }
    }

    /** Pedido do Mercado Pago → situação do sistema. */
    public static function translate(array $order): GatewayStatus
    {
        $status = (string) ($order['status'] ?? '');
        $detail = (string) ($order['status_detail'] ?? '');
        $payment = $order['transactions']['payments'][0] ?? [];

        $refunded = $status === 'refunded' || ($status === 'processed' && $detail === 'refunded') || $status === 'charged_back';
        $state = match (true) {
            $refunded => GatewayStatus::REFUNDED,
            $status === 'processed' => GatewayStatus::PAID,
            $status === 'expired' || ($detail === 'expired' && in_array($status, ['canceled', 'failed'], true)) => GatewayStatus::EXPIRED,
            $status === 'failed' => GatewayStatus::FAILED,
            $status === 'canceled' => GatewayStatus::CANCELED,
            default => GatewayStatus::PENDING,
        };

        return new GatewayStatus(
            state: $state,
            providerStatus: self::providerStatus($order),
            paymentId: ! empty($payment['id']) ? (string) $payment['id'] : null,
            paymentMethod: self::paymentMethod($payment),
        );
    }

    private static function providerStatus(array $order): string
    {
        return trim(($order['status'] ?? '').'/'.($order['status_detail'] ?? ''), '/');
    }

    /** Forma usada pelo cliente: cartão (crédito/débito) ou Pix; null quando o pedido ainda não diz. */
    private static function paymentMethod(array $payment): ?string
    {
        $type = (string) ($payment['payment_method']['type'] ?? '');
        $id = (string) ($payment['payment_method']['id'] ?? '');

        return match (true) {
            $type === 'credit_card' => Quote::PAYMENT_CREDIT,
            in_array($type, ['debit_card', 'prepaid_card'], true) => Quote::PAYMENT_DEBIT,
            $id === 'pix' || $type === 'bank_transfer' => Quote::PAYMENT_PIX,
            default => null,
        };
    }
}
