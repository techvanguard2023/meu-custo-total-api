<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bot do WhatsApp (um fluxo só no n8n para todas as lojas): cada chamada diz de qual loja é a conversa no cabeçalho
 * X-Bot-Session (o nome da sessão do WhatsApp da loja). Com a chave do serviço do bot (BOT_SERVICE_TOKEN), a chamada
 * passa a valer para ESSA loja — configurações, chave Pix, pedidos e atendimento ficam com ela, nunca com outra.
 * Roda antes do auth:sanctum; as rotas liberadas para o bot continuam limitadas em RestrictIntegrationTokens.
 */
class ResolveBotSession
{
    public const HEADER = 'X-Bot-Session';

    public function handle(Request $request, Closure $next): Response
    {
        $session = trim((string) $request->header(self::HEADER, ''));
        $serviceToken = (string) config('services.bot.service_token');

        if ($session === '' || $serviceToken === '' || ! hash_equals($serviceToken, (string) $request->bearerToken())) {
            return $next($request);
        }

        $company = Company::where('whatsapp_session_name', $session)->first();
        abort_unless($company, 404, 'Nenhuma loja está conectada com esta sessão do WhatsApp.');

        // O bot age como o dono da loja (é quem responde pela conta); as rotas permitidas são só as do bot
        $owner = $company->users()->where('role', User::ROLE_OWNER)->orderBy('id')->first()
            ?? $company->users()->orderBy('id')->first();
        abort_unless($owner, 404, 'A loja desta sessão não tem usuário.');

        $request->attributes->set('bot_service', true);
        auth()->guard('sanctum')->setUser($owner);
        $request->setUserResolver(fn () => $owner);

        return $next($request);
    }
}
