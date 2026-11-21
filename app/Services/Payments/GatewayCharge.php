<?php

namespace App\Services\Payments;

/** Cobrança recém-criada no gateway: o que o sistema guarda em payment_charges. */
final class GatewayCharge
{
    public function __construct(
        /** Id da cobrança/pedido no provedor (consultas, cancelamento, estorno e avisos do webhook) */
        public readonly ?string $externalId,
        /** Id do pagamento dentro dela, quando o provedor separa (opcional) */
        public readonly ?string $externalPaymentId,
        /** Situação como o provedor escreve (só para conferência/suporte) */
        public readonly ?string $providerStatus,
        /** Pix copia e cola (só Pix) */
        public readonly ?string $qrCode,
        /** Página do pagamento: link do cartão, ou a página do Pix no provedor */
        public readonly ?string $url,
    ) {}
}
