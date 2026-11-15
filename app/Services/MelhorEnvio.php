<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chamadas à API do Melhor Envio (app da plataforma + conta de cada loja via OAuth).
 *  - OAuth: {base}/oauth/authorize (escopo shipping-calculate) → POST {base}/oauth/token (código e renovação;
 *    o acesso vale 30 dias e o refresh 45).
 *  - Cotação: POST {base}/api/v2/me/shipment/calculate (origem, destino, produtos com medidas e peso em kg).
 *  - Obrigatório: cabeçalho User-Agent com o nome do app e um e-mail de contato.
 */
class MelhorEnvio
{
    public const SCOPES = 'shipping-calculate';

    public static function configured(): bool
    {
        return (bool) config('services.melhorenvio.client_id') && (bool) config('services.melhorenvio.client_secret');
    }

    public function base(): string
    {
        if ($override = config('services.melhorenvio.base_url')) {
            return rtrim($override, '/');
        }

        return config('services.melhorenvio.sandbox') ? 'https://sandbox.melhorenvio.com.br' : 'https://melhorenvio.com.br';
    }

    public function authorizationUrl(string $state): string
    {
        return $this->base().'/oauth/authorize?'.http_build_query([
            'client_id' => config('services.melhorenvio.client_id'),
            'redirect_uri' => config('services.melhorenvio.redirect_uri'),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int} */
    public function exchangeCode(string $code): array
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.melhorenvio.redirect_uri'),
        ]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    private function token(array $body): array
    {
        $response = Http::withHeaders(['User-Agent' => $this->userAgent(), 'Accept' => 'application/json'])
            ->timeout(20)->asForm()
            ->post($this->base().'/oauth/token', $body + [
                'client_id' => config('services.melhorenvio.client_id'),
                'client_secret' => config('services.melhorenvio.client_secret'),
            ]);
        if ($response->failed()) {
            throw new RuntimeException('O Melhor Envio recusou a autorização ('.$response->status().').');
        }
        $json = $response->json();
        if (empty($json['access_token'])) {
            throw new RuntimeException('O Melhor Envio não devolveu o acesso da conta.');
        }

        return $json;
    }

    /**
     * Cotação por produtos. Cada item: id, width/height/length (cm), weight (kg), insurance_value (unitário) e quantity.
     * Devolve as opções que a transportadora conseguiu cotar (sem as que vieram com erro).
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array{id: int, name: string, company: string, price: float, days: int, days_min: ?int, days_max: ?int}>
     *
     * @throws RuntimeException com frase para a tela; código 401 quando o acesso foi recusado
     */
    public function calculate(string $accessToken, string $fromCep, string $toCep, array $products): array
    {
        $response = $this->http($accessToken)->post($this->base().'/api/v2/me/shipment/calculate', [
            'from' => ['postal_code' => $fromCep],
            'to' => ['postal_code' => $toCep],
            'products' => $products,
            'options' => ['receipt' => false, 'own_hand' => false],
        ]);

        if ($response->status() === 401) {
            throw new RuntimeException('Acesso ao Melhor Envio recusado.', 401);
        }
        if ($response->status() === 422) {
            throw new RuntimeException('Confira os CEPs: o Melhor Envio não conseguiu calcular.', 422);
        }
        if ($response->failed()) {
            throw new RuntimeException('O Melhor Envio não respondeu agora. Tente de novo em instantes.', $response->status());
        }

        $options = [];
        foreach ((array) $response->json() as $row) {
            if (! is_array($row) || ! empty($row['error']) || ! isset($row['id'])) {
                continue;
            }
            // custom_* já refletem descontos e taxas da conta da loja; o resto é o valor de tabela
            $price = $row['custom_price'] ?? $row['price'] ?? null;
            if ($price === null || ! is_numeric($price)) {
                continue;
            }
            $range = $row['custom_delivery_range'] ?? $row['delivery_range'] ?? null;
            $days = (int) ($row['custom_delivery_time'] ?? $row['delivery_time'] ?? 0);
            $options[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? 'Envio'),
                'company' => (string) ($row['company']['name'] ?? ''),
                'price' => round((float) $price, 2),
                'days' => $days,
                'days_min' => isset($range['min']) ? (int) $range['min'] : null,
                'days_max' => isset($range['max']) ? (int) $range['max'] : null,
            ];
        }

        return $options;
    }

    private function http(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->withHeaders(['User-Agent' => $this->userAgent(), 'Accept' => 'application/json'])
            ->timeout(25)->acceptJson()->asJson();
    }

    private function userAgent(): string
    {
        return (string) config('services.melhorenvio.user_agent');
    }
}
