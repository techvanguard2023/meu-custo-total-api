<?php

namespace App\Services\Payments;

/**
 * Situação de uma cobrança no gateway, já traduzida para os estados do sistema. PixCharges::apply() decide o que
 * fazer com ela (baixa na venda, estorno, cancelar a outra cobrança).
 */
final class GatewayStatus
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const REFUNDED = 'refunded';

    public const CANCELED = 'canceled';

    public const EXPIRED = 'expired';

    /** Tentativa recusada (no link, o cliente ainda pode tentar de novo) */
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $state,
        /** Situação como o provedor escreve (fica em payment_charges.provider_status) */
        public readonly ?string $providerStatus = null,
        /** Id do pagamento no provedor (a baixa na venda usa para não entrar duas vezes) */
        public readonly ?string $paymentId = null,
        /** Forma usada pelo cliente (Quote::PAYMENT_PIX, _CREDIT, _DEBIT); null = não informado */
        public readonly ?string $paymentMethod = null,
    ) {}

    public function withState(string $state): self
    {
        return new self($state, $this->providerStatus, $this->paymentId, $this->paymentMethod);
    }
}
