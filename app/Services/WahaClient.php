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

    public function sendText(string $session, string $chatId, string $text, bool $linkPreview = false): void
    {
        $this->http()->timeout(8)->post('/sendText', array_filter([
            'session' => $session,
            'chatId' => $chatId,
            'text' => $text,
            // Prévia do link (foto e título do produto, vindos do catálogo) — usada nas ofertas
            'linkPreview' => $linkPreview ?: null,
        ], fn ($value) => $value !== null))->throw();
    }

    // ---- Grupos e canais de ofertas (validado no GOWS em 2026-11-02: criar, link, só admins,
    // descrição, postar texto/imagem; a contagem de membros só aparece depois do refresh;
    // apagar canal não é suportado pelo GOWS) ----

    /** Cria um grupo só com o número da loja e devolve o id (…@g.us). */
    public function createGroup(string $session, string $name): string
    {
        $group = $this->http()->timeout(30)->post("/{$session}/groups", ['name' => $name, 'participants' => []])->throw()->json();

        return (string) ($group['JID'] ?? $group['id'] ?? throw new \RuntimeException('A WAHA não devolveu o id do grupo.'));
    }

    /** Grupo de avisos: só administradores postam e mudam nome/foto/descrição. */
    public function setupAnnouncementGroup(string $session, string $groupId, ?string $description): void
    {
        $id = rawurlencode($groupId);
        $this->http()->put("/{$session}/groups/{$id}/settings/security/messages-admin-only", ['adminsOnly' => true])->throw();
        $this->http()->put("/{$session}/groups/{$id}/settings/security/info-admin-only", ['adminsOnly' => true])->throw();
        if ($description) {
            $this->http()->put("/{$session}/groups/{$id}/description", ['description' => $description])->throw();
        }
    }

    public function groupInviteUrl(string $session, string $groupId): string
    {
        $code = trim($this->http()->get("/{$session}/groups/".rawurlencode($groupId).'/invite-code')->throw()->body(), "\" \n");

        return str_starts_with($code, 'http') ? $code : "https://chat.whatsapp.com/{$code}";
    }

    /** Põe alguém no grupo e torna administrador (segundo admin da loja). */
    public function addGroupAdmin(string $session, string $groupId, string $chatId): void
    {
        $id = rawurlencode($groupId);
        $participants = ['participants' => [['id' => $chatId]]];
        $this->http()->post("/{$session}/groups/{$id}/participants/add", $participants)->throw();
        $this->http()->post("/{$session}/groups/{$id}/admin/promote", $participants)->throw();
    }

    /** Foto do grupo (JPEG). Quem tem o número da loja como admin pode trocar. */
    public function setGroupPicture(string $session, string $groupId, string $jpeg): void
    {
        $id = rawurlencode($groupId);
        $this->http()->timeout(40)->put("/{$session}/groups/{$id}/picture", [
            'file' => ['mimetype' => 'image/jpeg', 'filename' => 'grupo.jpg', 'data' => base64_encode($jpeg)],
        ])->throw();
    }

    public function deleteGroupPicture(string $session, string $groupId): void
    {
        $this->http()->timeout(30)->delete("/{$session}/groups/".rawurlencode($groupId).'/picture')->throw();
    }

    /** Nome do grupo. */
    public function setGroupSubject(string $session, string $groupId, string $subject): void
    {
        $this->http()->timeout(30)->put("/{$session}/groups/".rawurlencode($groupId).'/subject', ['subject' => $subject])->throw();
    }

    /**
     * Quantas pessoas cada grupo tem (id => total). Atualiza a lista na WAHA antes: sem isso, grupo
     * recém-criado não aparece e a contagem fica velha.
     *
     * @return array<string, int>
     */
    public function groupParticipantCounts(string $session): array
    {
        $this->http()->timeout(40)->post("/{$session}/groups/refresh")->throw();
        $groups = $this->http()->timeout(40)->get("/{$session}/groups")->throw()->json() ?? [];

        $counts = [];
        foreach ($groups as $key => $group) {
            $jid = $group['JID'] ?? $group['id'] ?? (is_string($key) ? $key : null);
            if ($jid) {
                $counts[$jid] = count($group['Participants'] ?? $group['participants'] ?? []);
            }
        }

        return $counts;
    }

    /** Canais em que a loja é dona ou admin (dá pra postar). @return array<int, array{id: string, name: string, invite: string|null}> */
    public function listOwnChannels(string $session): array
    {
        return collect($this->http()->get("/{$session}/channels")->throw()->json() ?? [])
            ->filter(fn ($c) => in_array($c['role'] ?? null, ['OWNER', 'ADMIN'], true))
            ->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'] ?? '', 'invite' => $c['invite'] ?? null])
            ->values()
            ->all();
    }

    /** @return array{id: string, invite: string|null} */
    public function createChannel(string $session, string $name, ?string $description): array
    {
        $channel = $this->http()->timeout(30)->post("/{$session}/channels", [
            'name' => $name,
            'description' => $description ?? '',
            'type' => 'BROADCAST',
        ])->throw()->json();

        return ['id' => $channel['id'], 'invite' => $channel['invite'] ?? null];
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
