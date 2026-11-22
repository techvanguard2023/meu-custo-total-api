<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Chamadas à API do PagBank (aplicação da plataforma no Connect + conta de cada loja). Documentação lida em
 * 2026-11-21 em developer.pagbank.com.br:
 *  - Connect: navegador em /oauth2/authorize (code de uso único, 10 min) → POST /oauth2/token com o token da
 *    plataforma + X_CLIENT_ID/X_CLIENT_SECRET; renovação em POST /oauth2/refresh (cada renovação invalida o
 *    refresh_token anterior — gravar o novo na hora).
 *  - Pix: POST /orders com qr_codes (customer com tax_id é obrigatório; o formato com charges[] PIX da doc foi recusado no sandbox).
 *  - Link: POST /checkouts (CHEC_...; o pagamento vira um pedido ORDE_... avisado no webhook). Inativar: POST
 *    /checkouts/{id}/inactivate. Pix de pedido não tem cancelamento — expira sozinho.
 *  - Estorno: POST /charges/{CHAR_...}/cancel {amount: {value}} (centavos).
 *  - Webhook: x-payload-signature = ECDSA SHA-256 (base64) do corpo bruto; chave pública em GET /public-keys/webhook (no sandbox, a forma com ?type=webhook da doc devolve 403).
 *  - Sem o Connect, a loja cola o token da própria conta (gerado no painel do PagBank): tokenWorks() confere, e o
 *    aviso traz x-authenticity-token = SHA-256 (hex) de "{token}-{corpo bruto}" — conferido com o token da loja.
 * Todos os valores em centavos inteiros.
 */
class PagBank
{
    public const SCOPES = ['payments.read', 'payments.create', 'payments.refund', 'checkout.create', 'checkout.view', 'checkout.update'];

    public static function configured(): bool
    {
        return (bool) config('services.pagbank.client_id') && (bool) config('services.pagbank.client_secret') && (bool) config('services.pagbank.platform_token');
    }

    public static function apiBase(): string
    {
        return config('services.pagbank.sandbox') ? 'https://sandbox.api.pagseguro.com' : 'https://api.pagseguro.com';
    }

    public static function connectBase(): string
    {
        return config('services.pagbank.sandbox') ? 'https://connect.sandbox.pagbank.com.br' : 'https://connect.pagbank.com.br';
    }

    public function authorizationUrl(string $state): string
    {
        // A doc pede os scopes separados por "+" (sem codificar o "+")
        return self::connectBase().'/oauth2/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.pagbank.client_id'),
            'redirect_uri' => config('services.pagbank.redirect_uri'),
            'state' => $state,
        ]).'&scope='.implode('+', self::SCOPES);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int|string, account_id: ?string, scope: ?string} */
    public function exchangeCode(string $code): array
    {
        return $this->oauth('/oauth2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config('services.pagbank.redirect_uri')]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->oauth('/oauth2/refresh', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    private function oauth(string $path, array $body): array
    {
        $response = $this->http((string) config('services.pagbank.platform_token'))
            ->withHeaders([
                'X_CLIENT_ID' => (string) config('services.pagbank.client_id'),
                'X_CLIENT_SECRET' => (string) config('services.pagbank.client_secret'),
            ])
            ->post(self::apiBase().$path, $body);
        $this->fail($response, 'autorização');
        $json = $response->json() ?? [];
        if (empty($json['access_token'])) {
            throw new RuntimeException('O PagBank não devolveu o acesso da conta.');
        }

        return $json;
    }

    /**
     * O token colado pela loja vale neste ambiente (sandbox ou produção)? Consulta um pedido que não existe:
     * 404 = token aceito; 401/403 = inválido, revogado ou de outro ambiente. Outra resposta = PagBankException.
     */
    public function tokenWorks(string $accessToken): bool
    {
        $response = $this->http($accessToken)->get(self::apiBase().'/orders/ORDE_00000000-0000-0000-0000-000000000000');
        if (in_array($response->status(), [401, 403], true)) {
            return false;
        }
        if ($response->successful() || $response->status() === 404) {
            return true;
        }
        $this->fail($response, 'conferência do token');

        return false;
    }

    /** Pedido com Pix (QR + copia e cola). $order no formato da API (valores em centavos). */
    public function createOrder(string $accessToken, array $order, string $idempotencyKey): array
    {
        $response = $this->http($accessToken)->withHeaders(['x-idempotency-key' => $idempotencyKey])->post(self::apiBase().'/orders', $order);
        $this->fail($response, 'Pix');

        return $response->json() ?? [];
    }

    public function getOrder(string $accessToken, string $orderId): array
    {
        $response = $this->http($accessToken)->get(self::apiBase().'/orders/'.rawurlencode($orderId));
        $this->fail($response, 'consulta do pedido');

        return $response->json() ?? [];
    }

    /** Link de pagamento (Checkout PagBank). */
    public function createCheckout(string $accessToken, array $checkout, string $idempotencyKey): array
    {
        $response = $this->http($accessToken)->withHeaders(['x-idempotency-key' => $idempotencyKey])->post(self::apiBase().'/checkouts', $checkout);
        $this->fail($response, 'link de pagamento');

        return $response->json() ?? [];
    }

    public function getCheckout(string $accessToken, string $checkoutId): array
    {
        $response = $this->http($accessToken)->get(self::apiBase().'/checkouts/'.rawurlencode($checkoutId));
        $this->fail($response, 'consulta do link');

        return $response->json() ?? [];
    }

    /** Inativa o link (o cliente não consegue mais pagar por ele). */
    public function inactivateCheckout(string $accessToken, string $checkoutId): void
    {
        $response = $this->http($accessToken)->post(self::apiBase().'/checkouts/'.rawurlencode($checkoutId).'/inactivate');
        // Já inativo, vencido ou pago: não é erro para quem chama
        if (! in_array($response->status(), [400, 409, 422], true)) {
            $this->fail($response, 'inativação do link');
        }
    }

    /** Estorno (total quando $cents = valor da cobrança). */
    public function cancelCharge(string $accessToken, string $chargeId, int $cents): array
    {
        $response = $this->http($accessToken)
            ->withHeaders(['x-idempotency-key' => (string) Str::uuid()])
            ->post(self::apiBase().'/charges/'.rawurlencode($chargeId).'/cancel', ['amount' => ['value' => $cents]]);
        $this->fail($response, 'estorno');

        return $response->json() ?? [];
    }

    /**
     * Confere a assinatura do aviso: x-payload-signature traz uma ou mais assinaturas (base64, separadas por vírgula
     * durante a troca de chaves) do corpo bruto, em ECDSA com SHA-256. Vale se uma delas bater.
     */
    public function validSignature(?string $header, string $rawBody): bool
    {
        $pem = $this->webhookPublicKey();
        if (! $header || ! $pem) {
            return false;
        }
        foreach (explode(',', $header) as $signature) {
            $decoded = base64_decode(trim($signature), true);
            if ($decoded !== false && $decoded !== '' && openssl_verify($rawBody, $decoded, $pem, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Chave pública dos webhooks (X.509 em base64 → PEM), guardada por um dia. */
    public function webhookPublicKey(): ?string
    {
        $token = (string) config('services.pagbank.platform_token');
        if ($token === '') {
            return null;
        }

        return Cache::remember('pagbank-webhook-public-key:'.(config('services.pagbank.sandbox') ? 'sandbox' : 'live'), now()->addDay(), function () use ($token) {
            $response = $this->http($token)->get(self::apiBase().'/public-keys/webhook');
            $this->fail($response, 'chave pública dos avisos');
            $key = (string) ($response->json('public_key') ?? '');
            if ($key === '') {
                throw new RuntimeException('O PagBank não devolveu a chave pública dos avisos.');
            }

            return str_contains($key, 'BEGIN PUBLIC KEY')
                ? $key
                : "-----BEGIN PUBLIC KEY-----\n".chunk_split(preg_replace('/\s+/', '', $key), 64, "\n")."-----END PUBLIC KEY-----\n";
        });
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function http(string $token): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(20)->connectTimeout(8)->withToken($token);
    }

    /** Erro da API → PagBankException com a mensagem do PagBank (vai para o log; a tela recebe uma frase curta). */
    private function fail(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }
        $json = $response->json() ?? [];
        $first = $json['error_messages'][0] ?? null;
        $detail = $first
            ? trim(($first['description'] ?? '').' '.($first['parameter_name'] ?? '').' '.($first['code'] ?? ''))
            : ($json['message'] ?? Str::limit($response->body(), 200));
        throw new PagBankException("PagBank ({$what}): HTTP {$response->status()} — {$detail}", $response->status());
    }
}
