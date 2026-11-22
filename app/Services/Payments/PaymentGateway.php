<?php

namespace App\Services\Payments;

use App\Models\Company;
use App\Models\PaymentCharge;
use Throwable;

/**
 * Gateway de pagamento online (Mercado Pago hoje; PagBank, Asaas... depois). O que é regra do Meu Custo Total —
 * sinal, pedido dividido, "o primeiro que pagar cancela o outro", baixa na venda, mensagens do bot — fica em
 * PixCharges; aqui fica só a conversa com o provedor, traduzida para GatewayCharge e GatewayStatus.
 *
 * Cada loja usa um gateway por vez (a conta conectada em payment_accounts diz qual). Erros de API sobem como
 * exceção (a mensagem vai para o log; quem chama mostra uma frase curta na tela).
 */
interface PaymentGateway
{
    /** Identificador gravado em payment_charges.provider e quote_payments.source (ex.: "mercadopago"). */
    public function key(): string;

    /** Nome para o lojista e o cliente (ex.: "Mercado Pago") — vai nas notas do pagamento e nas mensagens. */
    public function label(): string;

    /** Conta da loja conectada e pronta para cobrar. */
    public function ready(Company $company): bool;

    /**
     * Pix com QR Code e copia e cola, válido por $validityHours. Faltando um dado que o provedor exige do pagador
     * (ex.: CPF no PagBank), lança PaymentRefused com a frase para a tela.
     */
    public function createPix(Company $company, float $amount, string $reference, Payer $payer, int $validityHours, string $idempotencyKey): GatewayCharge;

    /** Link de pagamento (página do provedor: cartão, e Pix se o provedor oferecer). */
    public function createLink(Company $company, float $amount, string $title, string $reference, ?string $returnUrl, string $idempotencyKey): GatewayCharge;

    /** Situação atual da cobrança no provedor (recebe a cobrança inteira: alguns provedores usam mais de um id). */
    public function fetch(Company $company, PaymentCharge $charge): GatewayStatus;

    /**
     * Cancela uma cobrança ainda aberta (já paga, cancelada ou vencida não é erro). Provedor que não cancela
     * (ex.: Pix do PagBank só expira) não faz nada — a cobrança fica cancelada só no sistema.
     */
    public function cancel(Company $company, PaymentCharge $charge): void;

    /** Estorno total. Devolve a situação depois do pedido (já como estornada se o provedor aceitou). */
    public function refund(Company $company, PaymentCharge $charge): GatewayStatus;

    /** Erro de API: registra e, se o acesso foi recusado (ex.: 401), marca a conta para conectar de novo. */
    public function handleError(?Company $company, int $quoteId, Throwable $e): void;
}
