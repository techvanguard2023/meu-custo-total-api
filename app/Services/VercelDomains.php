<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Domínio próprio do catálogo: cadastra o domínio do lojista no projeto do frontend na Vercel
 * (que cuida do HTTPS) e consulta se o DNS dele já está apontando certo.
 */
class VercelDomains
{
    /** Alvo padrão do CNAME quando a Vercel não devolve um específico. */
    public const DEFAULT_CNAME = 'cname.vercel-dns.com';

    public function isConfigured(): bool
    {
        return ! empty(config('services.vercel.token')) && ! empty(config('services.vercel.project_id'));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://api.vercel.com')
            ->withToken((string) config('services.vercel.token'))
            ->acceptJson()
            ->timeout(15);
    }

    private function query(): array
    {
        return config('services.vercel.team_id') ? ['teamId' => config('services.vercel.team_id')] : [];
    }

    private function project(): string
    {
        return (string) config('services.vercel.project_id');
    }

    /**
     * Cadastra o domínio no projeto. Já estar cadastrado neste mesmo projeto não é erro.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function add(string $domain): array
    {
        $response = $this->http()->post("/v10/projects/{$this->project()}/domains?".http_build_query($this->query()), ['name' => $domain]);

        if ($response->successful()) {
            return ['ok' => true, 'error' => null];
        }

        $error = $response->json('error') ?? [];
        // 409: já está neste projeto (ok) ou em outro projeto/conta (não dá)
        if (($error['code'] ?? null) === 'domain_already_in_use' && ($error['projectId'] ?? null) === $this->project()) {
            return ['ok' => true, 'error' => null];
        }

        Log::warning("Vercel: não foi possível cadastrar o domínio {$domain}: ".$response->body());

        return [
            'ok' => false,
            'error' => ($error['code'] ?? null) === 'domain_already_in_use'
                ? 'Esse domínio já está em uso em outro projeto. Use outro endereço.'
                : 'Não foi possível cadastrar esse domínio agora. Confira se o endereço está correto e tente de novo.',
        ];
    }

    /**
     * Situação do domínio.
     *
     * @return array{active: bool, verified: bool, misconfigured: bool, cname: string, verification: array<int, array{type: string, domain: string, value: string}>}
     */
    public function status(string $domain): array
    {
        $project = $this->http()->get("/v9/projects/{$this->project()}/domains/{$domain}", $this->query());
        $config = $this->http()->get("/v6/domains/{$domain}/config", $this->query());

        // `verified`: o domínio é nosso pra usar (sem pendência de TXT). `misconfigured`: o DNS ainda não aponta pra Vercel.
        $verified = (bool) $project->json('verified', false);
        $misconfigured = $config->successful() ? (bool) $config->json('misconfigured', true) : true;

        return [
            'active' => $project->successful() && $verified && ! $misconfigured,
            'verified' => $verified,
            'misconfigured' => $misconfigured,
            'cname' => $config->json('recommendedCNAME.0.value') ?: self::DEFAULT_CNAME,
            'verification' => collect($project->json('verification', []))
                ->map(fn ($v) => ['type' => $v['type'] ?? 'TXT', 'domain' => $v['domain'] ?? '', 'value' => $v['value'] ?? ''])
                ->values()
                ->all(),
        ];
    }

    /** Pede pra Vercel reverificar (útil quando há registro TXT pendente). */
    public function verify(string $domain): void
    {
        $this->http()->post("/v9/projects/{$this->project()}/domains/{$domain}/verify?".http_build_query($this->query()));
    }

    public function remove(string $domain): void
    {
        $response = $this->http()->delete("/v9/projects/{$this->project()}/domains/{$domain}", $this->query());

        if ($response->failed() && $response->status() !== 404) {
            Log::warning("Vercel: não foi possível remover o domínio {$domain}: ".$response->body());
        }
    }
}
