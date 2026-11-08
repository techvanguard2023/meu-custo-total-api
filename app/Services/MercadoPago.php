<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Chamadas à API do Mercado Pago (app da plataforma + conta de cada loja via OAuth).
 *  - OAuth: https://auth.mercadopago.com/authorization → POST /oauth/token (código e renovação; 180 dias).
 *  - Pix pela API de Orders: POST /v1/orders (type online, processing_mode automatic), GET /v1/orders/{id}.
 *  - Avisos (webhook): x-signature = "ts=...,v1=..." com HMAC-SHA256 de "id:{data.id};request-id:{x-request-id};ts:{ts};".
 */
class MercadoPago
{
    private const API = 'https://api.mercadopago.com';

    private const AUTH = 'https://auth.mercadopago.com/authorization';

    public static function configured(): bool
    {
        return (bool) config('services.mercadopago.client_id') && (bool) config('services.mercadopago.client_secret');
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => config('services.mercadopago.client_id'),
            'response_type' => 'code',
            'platform_id' => 'mp',
            'state' => $state,
            'redirect_uri' => config('services.mercadopago.redirect_uri'),
        ]);
    }

    /** @return array{access_token: string, refresh_token: ?string, user_id: int|string, expires_in: int, public_key: ?string, live_mode: bool} */
    public function exchangeCode(string $code): array
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.mercadopago.redirect_uri'),
        ]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    private function token(array $body): array
    {
        $response = $this->http()->post(self::API.'/oauth/token', $body + [
            'client_id' => config('services.mercadopago.client_id'),
            'client_secret' => config('services.mercadopago.client_secret'),
        ]);
        $this->fail($response, 'autorização');
        $json = $response->json();
        if (empty($json['access_token']) || empty($json['user_id'])) {
            throw new RuntimeException('O Mercado Pago não devolveu o acesso da conta.');
        }

        return $json;
    }

    /**
     * Cria o Pix (pedido online, processamento automático). Valores em texto com 2 casas, como a API pede.
     *
     * @return array<string, mixed> o pedido (id ORD..., status, transactions.payments[0].payment_method.qr_code...)
     */
    public function createPixOrder(string $accessToken, float $amount, string $externalReference, string $payerEmail, string $expiration, string $idempotencyKey): array
    {
        $value = number_format($amount, 2, '.', '');
        $response = $this->http($accessToken)
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->post(self::API.'/v1/orders', [
                'type' => 'online',
                'processing_mode' => 'automatic',
                'total_amount' => $value,
                'external_reference' => Str::limit($externalReference, 64, ''),
                'payer' => ['email' => $payerEmail],
                'transactions' => ['payments' => [[
                    'amount' => $value,
                    'payment_method' => ['id' => 'pix', 'type' => 'bank_transfer'],
                    'expiration_time' => $expiration,
                ]]],
            ]);
        $this->fail($response, 'Pix');

        return $response->json();
    }

    /**
     * Link de pagamento (Checkout Pro pela API de Orders): página do Mercado Pago onde o cliente paga com cartão
     * (parcelado, conforme a conta da loja) ou Pix. A resposta traz `checkout_url`.
     */
    public function createCheckoutOrder(string $accessToken, float $amount, string $title, string $externalReference, ?string $returnUrl, string $idempotencyKey): array
    {
        $value = number_format($amount, 2, '.', '');
        $body = [
            'type' => 'online',
            'processing_mode' => 'manual',
            'total_amount' => $value,
            'external_reference' => Str::limit($externalReference, 64, ''),
            'expiration_time' => 'P1D',
            'items' => [['title' => Str::limit($title, 120, ''), 'unit_price' => $value, 'quantity' => 1]],
            // Boleto fica de fora: demora dias para compensar e o pedido já tem prazo
            'config' => ['payment_method' => ['not_allowed_types' => ['ticket']]],
        ];
        if ($returnUrl) {
            $body['config']['online'] = ['success_url' => $returnUrl, 'failure_url' => $returnUrl, 'pending_url' => $returnUrl, 'auto_return' => 'approved'];
        }
        $response = $this->http($accessToken)->withHeaders(['X-Idempotency-Key' => $idempotencyKey])->post(self::API.'/v1/orders', $body);
        $this->fail($response, 'link de pagamento');

        return $response->json();
    }

    /** Estorno total do pedido (o Mercado Pago devolve ao cliente; o aviso "order" atualiza a venda). */
    public function refundOrder(string $accessToken, string $orderId): array
    {
        $response = $this->http($accessToken)
            ->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->post(self::API.'/v1/orders/'.rawurlencode($orderId).'/refund');
        $this->fail($response, 'estorno');

        return $response->json() ?? [];
    }

    public function getOrder(string $accessToken, string $orderId): array
    {
        $response = $this->http($accessToken)->get(self::API.'/v1/orders/'.rawurlencode($orderId));
        $this->fail($response, 'consulta do pedido');

        return $response->json();
    }

    public function cancelOrder(string $accessToken, string $orderId): void
    {
        $response = $this->http($accessToken)
            ->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->post(self::API.'/v1/orders/'.rawurlencode($orderId).'/cancel');
        // Já pago, já cancelado ou expirado: não é erro para quem chama
        if ($response->status() !== 409) {
            $this->fail($response, 'cancelamento do Pix');
        }
    }

    /**
     * Confere a assinatura do aviso. O Mercado Pago monta "id:{data.id};request-id:{x-request-id};ts:{ts};"
     * (pares vazios saem) e assina com HMAC-SHA256 em hexadecimal. Aceita o id como veio e em minúsculas
     * (a documentação e o SDK divergem nesse ponto).
     */
    public static function validSignature(?string $header, ?string $requestId, ?string $dataId, string $secret): bool
    {
        if (! $header || $secret === '') {
            return false;
        }
        $ts = null;
        $v1 = null;
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            match (strtolower(trim($key))) {
                'ts' => $ts = trim($value),
                'v1' => $v1 = trim($value),
                default => null,
            };
        }
        if (! $ts || ! ctype_digit($ts) || ! $v1) {
            return false;
        }

        foreach (array_unique([trim((string) $dataId), strtolower(trim((string) $dataId))]) as $id) {
            $manifest = ($id !== '' ? "id:{$id};" : '').(trim((string) $requestId) !== '' ? 'request-id:'.trim($requestId).';' : '')."ts:{$ts};";
            if (hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($v1))) {
                return true;
            }
        }

        return false;
    }

    private function http(?string $accessToken = null): PendingRequest
    {
        $request = Http::acceptJson()->asJson()->timeout(20)->connectTimeout(8);

        return $accessToken ? $request->withToken($accessToken) : $request;
    }

    /** Erro da API → RuntimeException com a mensagem do Mercado Pago (vai para o log; a tela recebe uma frase curta). */
    private function fail(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }
        $json = $response->json() ?? [];
        $detail = $json['message'] ?? ($json['errors'][0]['message'] ?? null) ?? Str::limit($response->body(), 200);
        throw new MercadoPagoException("Mercado Pago ({$what}): HTTP {$response->status()} — {$detail}", $response->status());
    }
}
