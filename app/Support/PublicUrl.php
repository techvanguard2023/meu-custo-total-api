<?php

namespace App\Support;

/**
 * Endereço que o servidor pode buscar: só http(s) público. Sem essa conferência, um link colado pela loja (Achadinhos,
 * foto na planilha) viraria ponte para a rede interna do servidor. Conferir também cada redirecionamento.
 */
class PublicUrl
{
    /** null = pode buscar; 'invalid' = não é http(s) público; 'not_found' = o nome não existe. */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || in_array($parts['port'] ?? null, [22, 25, 3306, 6379], true)) {
            return 'invalid';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            return 'not_found';
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'invalid';
            }
        }

        return null;
    }
}
