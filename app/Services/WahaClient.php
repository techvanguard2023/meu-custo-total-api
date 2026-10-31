<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP fino pro WAHA (WhatsApp HTTP API) — uma sessão por empresa,
 * nomeada de forma previsível ("empresa-{id}") pra quem recebe a mensagem do
 * outro lado (o fluxo no n8n) saber de qual loja ela é sem perguntar pra gente.
 */
class WahaClient
{
    /** Marca no cache de chatIdFor: "esse número não tem WhatsApp" (null no cache vira "não consultado"). */
    private const NOT_ON_WHATSAPP = 'none';

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
     * Conversa certa pra um telefone cadastrado. No Brasil muitas contas de WhatsApp estão registradas sem
     * o 9º dígito: mandar pro número como está no cadastro "dá certo" na WAHA, mas a mensagem nunca chega.
     * A WAHA pergunta ao WhatsApp e devolve o id real. null = o número não tem WhatsApp.
     * Se a consulta falhar, usa o formato antigo (55 + número) pra não deixar de tentar.
     */
    public function chatIdFor(string $session, string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '') {
            return null;
        }

        $number = strlen($digits) <= 11 ? '55'.$digits : $digits;
        $cacheKey = "waha-chat-id:{$session}:{$number}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === self::NOT_ON_WHATSAPP ? null : $cached;
        }

        // Celular brasileiro com o 9: se não achar assim, tenta sem ele (conta antiga)
        $candidates = [$number];
        if (strlen($number) === 13 && str_starts_with($number, '55') && $number[4] === '9') {
            $candidates[] = substr($number, 0, 4).substr($number, 5);
        }

        try {
            foreach ($candidates as $candidate) {
                $response = $this->http()->timeout(8)->get('/contacts/check-exists', ['phone' => $candidate, 'session' => $session])->throw();

                if ($response->json('numberExists') === true) {
                    $chatId = (string) ($response->json('chatId') ?: $candidate.'@c.us');
                    Cache::put($cacheKey, $chatId, now()->addDays(30));

                    return $chatId;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("WAHA: não foi possível conferir o WhatsApp de {$this->maskPhone($number)}: {$e->getMessage()}");

            return $number.'@c.us';
        }

        // Sem WhatsApp: guarda por pouco tempo (a pessoa pode criar a conta depois)
        Cache::put($cacheKey, self::NOT_ON_WHATSAPP, now()->addHour());
        Log::info("WAHA: {$this->maskPhone($number)} não tem WhatsApp — mensagem não enviada.");

        return null;
    }

    /** Telefone com só os 4 últimos dígitos, pro log. */
    private function maskPhone(string $number): string
    {
        return '***'.substr($number, -4);
    }

    public function sendText(string $session, string $chatId, string $text): void
    {
        $this->http()->timeout(8)->post('/sendText', [
            'session' => $session,
            'chatId' => $chatId,
            'text' => $text,
        ])->throw();
    }

    /** Manda uma imagem (bytes em base64) com legenda — ex: o cartão de cashback. */
    public function sendImage(string $session, string $chatId, string $bytes, string $mimetype, string $filename, ?string $caption = null): void
    {
        $this->http()->timeout(20)->post('/sendImage', [
            'session' => $session,
            'chatId' => $chatId,
            'file' => ['mimetype' => $mimetype, 'filename' => $filename, 'data' => base64_encode($bytes)],
            'caption' => $caption,
        ])->throw();
    }

    /** Manda um arquivo (ex: PDF de orçamento) a partir de uma URL pública acessível pela WAHA. */
    public function sendFile(string $session, string $chatId, string $fileUrl, string $filename, ?string $caption = null): void
    {
        $this->http()->timeout(15)->post('/sendFile', [
            'session' => $session,
            'chatId' => $chatId,
            'file' => ['mimetype' => 'application/pdf', 'filename' => $filename, 'url' => $fileUrl],
            'caption' => $caption,
        ])->throw();
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
