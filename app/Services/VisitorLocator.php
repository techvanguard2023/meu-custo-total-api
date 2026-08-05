<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Converte o IP de um visitante em cidade/estado. O IP é usado apenas nesta
 * consulta e nunca é gravado — só o resultado (ex: "Campinas/SP") vai para o banco.
 *
 * O resultado fica em cache por rede, então o serviço externo é consultado no
 * máximo uma vez a cada 30 dias por IP — mesmo com muitos acessos.
 */
class VisitorLocator
{
    private const CACHE_PREFIX = 'visitor-location:';
    private const CACHE_DAYS = 30;
    /** Falha fica em cache por pouco tempo: evita insistir num serviço fora do ar, mas se recupera sozinho. */
    private const FAILURE_CACHE_HOURS = 6;
    private const TIMEOUT_SECONDS = 3;

    /**
     * @return array{country_code: ?string, region: ?string, city: ?string}|null
     */
    public static function resolve(?string $ip): ?array
    {
        // Redes privadas/loopback (ambiente local) não têm localização pública
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $key = self::CACHE_PREFIX.$ip;
        $cached = Cache::get($key);

        // Array vazio é o marcador de "já tentei e não consegui" — sem ele, o
        // Cache::remember trataria null como ausência e repetiria a consulta sempre.
        if ($cached !== null) {
            return $cached ?: null;
        }

        $location = self::fetch($ip);

        Cache::put(
            $key,
            $location ?? [],
            $location ? now()->addDays(self::CACHE_DAYS) : now()->addHours(self::FAILURE_CACHE_HOURS)
        );

        return $location;
    }

    private static function fetch(string $ip): ?array
    {
        try {
            // Só os campos necessários — a API não recebe nem devolve nada além disso.
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,countryCode,region,city',
                    'lang' => 'pt-BR',
                ]);

            $data = $response->json();

            if (! is_array($data) || ($data['status'] ?? null) !== 'success') {
                return null;
            }

            return [
                'country_code' => $data['countryCode'] ?? null,
                'region' => $data['region'] ?? null,   // sigla do estado, ex: "SP"
                'city' => $data['city'] ?? null,
            ];
        } catch (\Throwable $e) {
            // Métrica nunca pode atrapalhar o catálogo: falhou, fica sem localização.
            report($e);

            return null;
        }
    }
}
