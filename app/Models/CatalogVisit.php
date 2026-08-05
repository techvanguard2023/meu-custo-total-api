<?php

namespace App\Models;

use App\Services\VisitorLocator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

use function Illuminate\Support\defer;

/**
 * Visita ao catálogo público. Não guarda IP nem nenhum dado pessoal: só uma
 * impressão digital anônima (hash) que serve para contar visitantes distintos
 * no mesmo dia. Como o sal do hash muda diariamente, a mesma pessoa vira um
 * hash diferente amanhã — não dá pra reconstruir um histórico individual.
 */
class CatalogVisit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'visitor_hash', 'visit_date', 'visited_at',
        'country_code', 'region', 'city',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'visited_at' => 'datetime',
    ];

    /**
     * Trechos de User-Agent de robôs que não devem contar como visita.
     * Os crawlers de preview de link (WhatsApp, Facebook etc.) batem no mesmo
     * endpoint toda vez que alguém compartilha o link — sem isso, compartilhar
     * inflaria a contagem sem ninguém ter aberto o catálogo de fato.
     */
    private const BOT_AGENTS = [
        'bot', 'crawler', 'spider', 'slurp', 'facebookexternalhit', 'facebot',
        'whatsapp', 'telegram', 'twitterbot', 'slackbot', 'linkedinbot',
        'discordbot', 'pinterest', 'applebot', 'embedly', 'quora link preview',
        'vkshare', 'redditbot', 'preview', 'headlesschrome', 'lighthouse',
        'gtmetrix', 'pingdom', 'uptimerobot', 'curl/', 'wget/', 'python-requests',
        'axios/', 'node-fetch', 'go-http-client', 'okhttp', 'postman',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Requisição que não deve ser contabilizada (robô ou chamada interna). */
    public static function isBot(Request $request): bool
    {
        // A função serverless que gera o preview do link marca a própria chamada
        // com este header — ela busca os dados do catálogo em nome de um robô.
        if ($request->hasHeader('X-No-Track')) {
            return true;
        }

        $agent = strtolower((string) $request->userAgent());

        if ($agent === '') {
            return true; // sem User-Agent: quase sempre script/robô, nunca navegador real
        }

        foreach (self::BOT_AGENTS as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Registra a visita, ignorando robôs. Nunca deve derrubar a página do catálogo. */
    public static function record(Company $company, Request $request): void
    {
        if (self::isBot($request)) {
            return;
        }

        try {
            $today = now()->toDateString();
            $ip = $request->ip();

            $hash = hash('sha256', implode('|', [
                $ip,
                $request->userAgent(),
                $company->id,
                $today,                 // sal diário: impede rastrear a mesma pessoa entre dias
                config('app.key'),      // impede que alguém de fora recalcule o hash
            ]));

            $visit = self::create([
                'company_id' => $company->id,
                'visitor_hash' => $hash,
                'visit_date' => $today,
                'visited_at' => now(),
            ]);

            // A localização é resolvida DEPOIS que a resposta já foi enviada ao
            // visitante: o catálogo não espera pela consulta de geolocalização.
            // O IP fica só nesta closure em memória — não é gravado em lugar nenhum.
            defer(function () use ($visit, $ip) {
                $location = VisitorLocator::resolve($ip);

                if ($location) {
                    $visit->update($location);
                }
            });
        } catch (\Throwable $e) {
            // Métrica nunca pode quebrar a experiência do cliente final vendo o catálogo.
            report($e);
        }
    }
}
