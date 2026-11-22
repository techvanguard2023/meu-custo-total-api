<?php

namespace App\Services\Payments;

use App\Models\Company;
use App\Models\PaymentCharge;
use App\Models\Quote;
use App\Services\PagBank;
use App\Services\PagBankAccounts;
use App\Services\PagBankException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * PagBank como gateway:
 *  - Pix: pedido (ORDE_...) com QR em qr_codes; pago, ganha a cobrança CHAR_... em charges[]. O PagBank exige o
 *    CPF/CNPJ do comprador — sem ele, PaymentRefused (a tela sugere o link). Pix não pago não tem cancelamento:
 *    expira sozinho.
 *  - Link: Checkout PagBank (CHEC_...: cartão de crédito ou Pix). O pagamento vira um pedido ORDE_..., avisado
 *    no webhook (PagBankWebhookController guarda o id dele em meta.order_id).
 *  - Estorno: cancela a cobrança CHAR_... com o valor todo.
 */
class PagBankGateway implements PaymentGateway
{
    public function __construct(private PagBank $api, private PagBankAccounts $accounts) {}

    public function key(): string
    {
        return PagBankAccounts::PROVIDER;
    }

    public function label(): string
    {
        return 'PagBank';
    }

    public function ready(Company $company): bool
    {
        return $this->accounts->ready($company);
    }

    public function createPix(Company $company, float $amount, string $reference, Payer $payer, int $validityHours, string $idempotencyKey): GatewayCharge
    {
        if (! $payer->taxId) {
            throw new PaymentRefused('Para gerar Pix pelo PagBank, cadastre o CPF ou CNPJ do cliente. Ou gere o link de pagamento (cartão ou Pix), que não pede o documento.');
        }
        $cents = PagBank::cents($amount);
        $customer = ['name' => Str::limit($payer->name, 120, ''), 'email' => $payer->email, 'tax_id' => $payer->taxId];
        if ($payer->phone) {
            $customer['phones'] = [['country' => '55', 'area' => substr($payer->phone, 0, 2), 'number' => substr($payer->phone, 2), 'type' => 'MOBILE']];
        }

        $order = $this->api->createOrder($this->accounts->token($company), [
            'reference_id' => Str::limit($reference, 64, ''),
            'customer' => $customer,
            'items' => [['reference_id' => Str::limit($reference, 64, ''), 'name' => 'Pedido '.Str::limit($company->name, 80, ''), 'quantity' => 1, 'unit_amount' => $cents]],
            // QR no nível do pedido (qr_codes). O formato com charges[].payment_method PIX da doc foi recusado no
            // sandbox ("Pix key was not found") com a mesma conta; este foi aceito. A cobrança CHAR_... só aparece
            // em charges[] depois do pagamento.
            'qr_codes' => [['amount' => ['value' => $cents], 'expiration_date' => $this->isoIn($validityHours)]],
        ] + self::webhookField('notification_urls'), $idempotencyKey);
        $charge = $order['charges'][0] ?? [];

        return new GatewayCharge(
            externalId: $order['id'] ?? null,
            externalPaymentId: $charge['id'] ?? null,
            providerStatus: $charge['status'] ?? 'WAITING',
            qrCode: self::qrText($order),
            url: null,
        );
    }

    public function createLink(Company $company, float $amount, string $title, string $reference, ?string $returnUrl, string $idempotencyKey): GatewayCharge
    {
        $body = [
            'reference_id' => Str::limit($reference, 64, ''),
            'expiration_date' => $this->isoIn(24),
            'customer_modifiable' => true,
            'items' => [['reference_id' => Str::limit($reference, 64, ''), 'name' => Str::limit($title, 100, ''), 'quantity' => 1, 'unit_amount' => PagBank::cents($amount)]],
            // Débito precisa de liberação do PagBank e boleto demora dias: cartão de crédito e Pix
            'payment_methods' => [['type' => 'CREDIT_CARD'], ['type' => 'PIX']],
        ] + self::webhookField('notification_urls') + self::webhookField('payment_notification_urls');
        if ($returnUrl) {
            $body['redirect_url'] = Str::limit($returnUrl, 255, '');
        }
        $checkout = $this->api->createCheckout($this->accounts->token($company), $body, $idempotencyKey);

        return new GatewayCharge(
            externalId: $checkout['id'] ?? null,
            externalPaymentId: null,
            providerStatus: $checkout['status'] ?? 'ACTIVE',
            qrCode: null,
            url: self::link($checkout, 'PAY'),
        );
    }

    public function fetch(Company $company, PaymentCharge $charge): GatewayStatus
    {
        $token = $this->accounts->token($company);
        if ($orderId = self::orderId($charge)) {
            return self::translateOrder($this->api->getOrder($token, $orderId));
        }

        $checkout = $this->api->getCheckout($token, (string) $charge->external_id);
        // Se a consulta do link já trouxer o pedido pago, segue por ele (a doc não garante este campo)
        $paidOrder = $checkout['orders'][0]['id'] ?? null;
        if ($paidOrder) {
            return self::translateOrder($this->api->getOrder($token, (string) $paidOrder));
        }

        return self::translateCheckout($checkout);
    }

    public function cancel(Company $company, PaymentCharge $charge): void
    {
        // Pix de pedido não tem cancelamento no PagBank (expira sozinho); o link é inativado
        if ($charge->method === PaymentCharge::METHOD_LINK && str_starts_with((string) $charge->external_id, 'CHEC_')) {
            $this->api->inactivateCheckout($this->accounts->token($company), $charge->external_id);
        }
    }

    public function refund(Company $company, PaymentCharge $charge): GatewayStatus
    {
        $token = $this->accounts->token($company);
        $chargeId = str_starts_with((string) $charge->external_payment_id, 'CHAR_') ? $charge->external_payment_id : $this->fetch($company, $charge)->paymentId;
        if (! $chargeId) {
            throw new PagBankException('PagBank (estorno): cobrança paga não encontrada no pedido.', 404);
        }
        $this->api->cancelCharge($token, $chargeId, PagBank::cents((float) $charge->amount));
        $status = $this->fetch($company, $charge);

        // Estorno aceito; a consulta logo depois pode ainda não mostrar — vale como estornado
        return $status->state === GatewayStatus::REFUNDED ? $status : $status->withState(GatewayStatus::REFUNDED);
    }

    public function handleError(?Company $company, int $quoteId, Throwable $e): void
    {
        Log::warning("PagBank (venda {$quoteId}): ".Str::limit($e->getMessage(), 300));
        if ($company && $e instanceof PagBankException && $e->getCode() === 401) {
            $this->accounts->revoke($company);
        }
    }

    /** Pedido do PagBank → situação do sistema (pela primeira cobrança; o sistema faz uma cobrança por pedido). */
    public static function translateOrder(array $order): GatewayStatus
    {
        $charge = $order['charges'][0] ?? null;
        if (! $charge) {
            $expires = $order['qr_codes'][0]['expiration_date'] ?? $order['qr_code'][0]['expiration_date'] ?? null;

            return new GatewayStatus($expires && Carbon::parse($expires)->isPast() ? GatewayStatus::EXPIRED : GatewayStatus::PENDING, 'WAITING');
        }

        $status = strtoupper((string) ($charge['status'] ?? ''));
        $summary = $charge['amount']['summary'] ?? [];
        $total = (int) ($summary['total'] ?? $charge['amount']['value'] ?? 0);
        $refunded = (int) ($summary['refunded'] ?? 0);
        $paid = (int) ($summary['paid'] ?? 0);
        $expires = $charge['payment_method']['pix']['expiration_date'] ?? null;

        $state = match (true) {
            $refunded > 0 && $refunded >= $total => GatewayStatus::REFUNDED,
            $status === 'PAID' => GatewayStatus::PAID,
            $status === 'CANCELED' && ($paid > 0 || $refunded > 0) => GatewayStatus::REFUNDED,
            $status === 'CANCELED' && $expires && Carbon::parse($expires)->isPast() => GatewayStatus::EXPIRED,
            $status === 'CANCELED' => GatewayStatus::CANCELED,
            $status === 'DECLINED' => GatewayStatus::FAILED,
            default => GatewayStatus::PENDING,
        };

        return new GatewayStatus(
            state: $state,
            providerStatus: $status ?: null,
            paymentId: $charge['id'] ?? null,
            paymentMethod: match (strtoupper((string) ($charge['payment_method']['type'] ?? ''))) {
                'PIX' => Quote::PAYMENT_PIX,
                'CREDIT_CARD' => Quote::PAYMENT_CREDIT,
                'DEBIT_CARD' => Quote::PAYMENT_DEBIT,
                default => null,
            },
        );
    }

    /** Link ainda sem pagamento: ativo, vencido ou inativado. */
    public static function translateCheckout(array $checkout): GatewayStatus
    {
        $status = strtoupper((string) ($checkout['status'] ?? ''));

        return new GatewayStatus(match ($status) {
            'EXPIRED' => GatewayStatus::EXPIRED,
            'INACTIVE' => GatewayStatus::CANCELED,
            default => GatewayStatus::PENDING,
        }, $status ?: null);
    }

    /** Pix copia e cola: na criação vem em charges[].qr_code (objeto); na consulta, às vezes em qr_codes (lista). */
    public static function qrText(array $order): ?string
    {
        $qr = $order['charges'][0]['qr_code'] ?? null;
        $text = is_array($qr) ? ($qr['text'] ?? $qr[0]['text'] ?? null) : null;

        return $text ?? $order['qr_codes'][0]['text'] ?? $order['qr_code'][0]['text'] ?? null;
    }

    /** Pedido da cobrança: o próprio (Pix) ou o que o link gerou ao ser pago (avisado no webhook). */
    public static function orderId(PaymentCharge $charge): ?string
    {
        if (str_starts_with((string) $charge->external_id, 'ORDE_')) {
            return $charge->external_id;
        }

        return $charge->meta['order_id'] ?? null;
    }

    /**
     * Endereço dos avisos, só quando é público e HTTPS: o PagBank recusa o pedido inteiro com um endereço local
     * ("invalid notification url"). Sem ele, a baixa vem pela consulta (tela aberta, "Meus pedidos", próximo aviso).
     *
     * @return array<string, list<string>>
     */
    private static function webhookField(string $field): array
    {
        $url = (string) config('services.pagbank.webhook_url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $local = $host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP) !== false;

        return str_starts_with($url, 'https://') && ! $local ? [$field => [$url]] : [];
    }

    private static function link(array $resource, string $rel): ?string
    {
        foreach ($resource['links'] ?? [] as $link) {
            if (strtoupper((string) ($link['rel'] ?? '')) === $rel) {
                return $link['href'] ?? null;
            }
        }

        return null;
    }

    private function isoIn(int $hours): string
    {
        return now('America/Sao_Paulo')->addHours($hours)->format('Y-m-d\TH:i:sP');
    }
}
