<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP fino pro WAHA (WhatsApp HTTP API) — uma sessão por empresa,
 * nomeada de forma previsível ("empresa-{id}") pra quem recebe a mensagem do
 * outro lado (o fluxo no n8n) saber de qual loja ela é sem perguntar pra gente.
 */
class WahaClient
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.waha.url')) && ! empty(config('services.waha.api_key'));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.waha.url'), '/').'/api')
            ->withHeaders(['X-Api-Key' => config('services.waha.api_key')])
            ->acceptJson()
            ->timeout(15);
    }

    /** null quando a sessão nunca foi criada (ou foi deletada). */
    public function getSession(string $name): ?array
    {
        $response = $this->http()->get("/sessions/{$name}");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json();
    }

    /**
     * Garante que a sessão existe e está iniciando/rodando. Cria na primeira
     * vez; nas seguintes (ex: depois de um logout), só reinicia — a própria
     * WAHA já devolve pra "aguardando QR code" sozinha.
     */
    public function ensureSessionStarted(string $name, array $config = []): array
    {
        $existing = $this->getSession($name);

        if ($existing === null) {
            $payload = ['name' => $name, 'start' => true];
            if ($config !== []) {
                $payload['config'] = $config;
            }
            $response = $this->http()->post('/sessions', $payload);
        } else {
            // Sessão já existe: alinha a configuração com o que está salvo antes de reiniciar.
            $this->updateConfig($name, $config);
            $response = $this->http()->post("/sessions/{$name}/start");
        }

        $response->throw();

        return $response->json();
    }

    /**
     * Atualiza chaves da configuração da sessão (ex: webhooks, ignore). A WAHA
     * SUBSTITUI o config inteiro no PUT, então parte do que já existe e só
     * troca as chaves pedidas — senão mudar o webhook apagaria os filtros.
     */
    public function updateConfig(string $name, array $changes): void
    {
        $current = $this->getSession($name)['config'] ?? [];

        $this->http()->put("/sessions/{$name}", ['config' => array_merge($current, $changes)])->throw();
    }

    /** QR code pronto pra usar num <img src>; null quando a sessão não está esperando scan. */
    public function getQrCodeDataUri(string $name): ?string
    {
        $response = $this->http()->get("/{$name}/auth/qr");

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return isset($data['data'], $data['mimetype']) ? "data:{$data['mimetype']};base64,{$data['data']}" : null;
    }

    /**
     * Desloga e para a sessão (sem apagar) — reconectar depois só pede um QR
     * novo. Só o logout não é suficiente: a WAHA reinicia sozinha pra
     * "aguardando QR" em seguida, o que faria a tela parecer que está
     * tentando reconectar sem o lojista ter pedido.
     */
    public function logoutSession(string $name): void
    {
        $this->http()->post("/sessions/{$name}/logout");
        $this->http()->post("/sessions/{$name}/stop");
    }
}
