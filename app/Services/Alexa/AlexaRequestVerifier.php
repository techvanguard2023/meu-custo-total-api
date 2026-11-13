<?php

namespace App\Services\Alexa;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Confere se a requisição veio mesmo da Alexa, como a Amazon exige de toda skill com endpoint próprio:
 * certificado baixado só de s3.amazonaws.com/echo.api/, válido e emitido pra echo-api.amazon.com, assinatura
 * SHA-256 do corpo bruto, horário com no máximo 150 s de diferença e a skill certa (applicationId).
 */
class AlexaRequestVerifier
{
    public const MAX_AGE_SECONDS = 150;

    private const CERT_SAN = 'echo-api.amazon.com';

    /**
     * @param  Closure(string): ?string|null  $fetchCertificate  baixa o PEM da cadeia (troca nos testes)
     * @param  string|null  $caFile  autoridades confiáveis (padrão: as do sistema)
     */
    public function __construct(
        private readonly ?string $skillId = null,
        private ?Closure $fetchCertificate = null,
        private readonly ?string $caFile = null,
    ) {
        $this->fetchCertificate ??= function (string $url): ?string {
            $response = Http::timeout(5)->get($url);

            return $response->successful() ? $response->body() : null;
        };
    }

    /** Devolve o motivo da recusa, ou null quando está tudo certo. */
    public function failure(string $body, ?string $certUrl, ?string $signature): ?string
    {
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return 'corpo inválido';
        }

        $appId = data_get($payload, 'context.System.application.applicationId') ?? data_get($payload, 'session.application.applicationId');
        if (! $this->skillId || $appId !== $this->skillId) {
            return 'skill diferente';
        }

        $timestamp = strtotime((string) data_get($payload, 'request.timestamp'));
        if (! $timestamp || abs(time() - $timestamp) > self::MAX_AGE_SECONDS) {
            return 'requisição fora do horário';
        }

        if (! $certUrl || ! self::validCertUrl($certUrl)) {
            return 'endereço do certificado inválido';
        }
        if (! $signature) {
            return 'sem assinatura';
        }

        $pem = $this->certificate($certUrl);
        if (! $pem) {
            return 'certificado indisponível';
        }
        $failure = $this->certificateFailure($pem);
        if ($failure) {
            return $failure;
        }

        $key = openssl_pkey_get_public($pem);
        $decoded = base64_decode($signature, true);
        if (! $key || $decoded === false || openssl_verify($body, $decoded, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return 'assinatura inválida';
        }

        return null;
    }

    /** https://s3.amazonaws.com(:443)/echo.api/... — host sem diferenciar maiúscula, caminho já normalizado. */
    public static function validCertUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || strtolower($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 's3.amazonaws.com') {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $segments = [];
        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return ($segments[0] ?? null) === 'echo.api';
    }

    private function certificate(string $url): ?string
    {
        $cached = Cache::get($this->cacheKey($url));
        if (is_string($cached)) {
            return $cached;
        }

        $pem = ($this->fetchCertificate)($url);
        if ($pem) {
            Cache::put($this->cacheKey($url), $pem, now()->addHours(12));
        }

        return $pem ?: null;
    }

    private function cacheKey(string $url): string
    {
        return 'alexa-cert:'.sha1($url);
    }

    private function certificateFailure(string $pem): ?string
    {
        $cert = openssl_x509_read($pem);
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (! $info) {
            return 'certificado ilegível';
        }

        $now = time();
        if ($now < ($info['validFrom_time_t'] ?? PHP_INT_MAX) || $now > ($info['validTo_time_t'] ?? 0)) {
            return 'certificado vencido';
        }

        $sans = array_map(
            fn ($entry) => strtolower(trim(str_replace('DNS:', '', $entry))),
            explode(',', (string) ($info['extensions']['subjectAltName'] ?? ''))
        );
        if (! in_array(self::CERT_SAN, $sans, true)) {
            return 'certificado não é da Alexa';
        }

        // Os demais certificados do PEM são os intermediários da cadeia
        $untrusted = tempnam(sys_get_temp_dir(), 'alexa');
        file_put_contents($untrusted, $pem);
        try {
            $ca = $this->caFile ? [$this->caFile] : [];
            $trusted = openssl_x509_checkpurpose($cert, X509_PURPOSE_ANY, $ca, $untrusted);
        } finally {
            @unlink($untrusted);
        }

        return $trusted === true ? null : 'cadeia do certificado não confiável';
    }
}
