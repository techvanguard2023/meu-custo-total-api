<?php

namespace App\Services\Payments;

use App\Models\Company;
use App\Models\PaymentAccount;
use App\Models\PaymentCharge;
use InvalidArgumentException;

/**
 * Qual gateway usar: o da conta conectada pela loja (payment_accounts.provider), ou o padrão quando ela ainda
 * não conectou nenhum (as frases de "conecte sua conta" falam dele). Cobrança já criada segue no gateway em que
 * nasceu (payment_charges.provider), mesmo que a loja troque de gateway depois.
 *
 * Gateway novo: implementar PaymentGateway e incluir em GATEWAYS. Registrado como singleton (AppServiceProvider).
 */
class PaymentGateways
{
    public const DEFAULT = 'mercadopago';

    /** @var array<string, class-string<PaymentGateway>> */
    private const GATEWAYS = [
        'mercadopago' => MercadoPagoGateway::class,
        'pagbank' => PagBankGateway::class,
    ];

    /** @var array<string, class-string<PaymentGateway>> */
    private array $gateways = self::GATEWAYS;

    /** Gateway da loja (o da conta conectada, ou o padrão). */
    public function for(Company $company): PaymentGateway
    {
        $provider = PaymentAccount::where('company_id', $company->id)->value('provider');

        return $this->get($provider && isset($this->gateways[$provider]) ? $provider : self::DEFAULT);
    }

    /** Gateway em que a cobrança foi criada. */
    public function forCharge(PaymentCharge $charge): PaymentGateway
    {
        return $this->get($charge->provider ?: self::DEFAULT);
    }

    public function get(string $key): PaymentGateway
    {
        $class = $this->gateways[$key] ?? throw new InvalidArgumentException("Gateway de pagamento desconhecido: {$key}");

        return app($class);
    }

    public function default(): PaymentGateway
    {
        return $this->get(self::DEFAULT);
    }

    /** Registra (ou troca) um gateway — usado pelos testes para provar que outro provedor encaixa. */
    public function extend(string $key, string $class): void
    {
        $this->gateways[$key] = $class;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->gateways);
    }
}
