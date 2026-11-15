<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token de integração (o do bot no n8n) só acessa o que o bot usa — vazou, não abre o resto da conta.
 * Token de login ("api") e o do "Entrar como" da área admin seguem com acesso normal.
 */
class RestrictIntegrationTokens
{
    /** [método, rota] que o bot chama (ver o fluxo "MeuCustoTotal - Bot" no n8n). */
    private const ALLOWED = [
        ['GET', 'api/v1/whatsapp/settings'],
        ['GET', 'api/v1/whatsapp/handoffs'],
        ['GET', 'api/v1/whatsapp/handoffs/{phone}'],
        ['POST', 'api/v1/whatsapp/handoffs'],
        ['DELETE', 'api/v1/whatsapp/handoffs/{phone}'],
        ['GET', 'api/v1/whatsapp/cashback/{phone}'],
        ['POST', 'api/v1/whatsapp/display-contacts'],
        ['POST', 'api/v1/quotes/external-order'],
        ['GET', 'api/v1/whatsapp/order-payment'],
        ['POST', 'api/v1/whatsapp/order-notes'],
        ['POST', 'api/v1/quotes/external-quote-request'],
        ['GET', 'api/v1/whatsapp/delivery-dates'],
    ];

    public static function isIntegration(?object $token): bool
    {
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        return $token->name !== 'api' && ! str_starts_with((string) $token->name, 'impersonation:');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $botService = (bool) $request->attributes->get('bot_service');

        if ($botService || self::isIntegration($request->user()?->currentAccessToken())) {
            $route = [$request->method(), $request->route()?->uri()];
            abort_unless(in_array($route, self::ALLOWED, true), 403, 'Este token de integração não tem acesso a esta área.');
        }

        // A conversa é de uma loja (X-Bot-Session) e o token é de outra: recusa, senão a chave Pix, os pedidos e o
        // atendimento iriam para a loja errada (foi o que aconteceu com um token só para todas as lojas)
        $session = trim((string) $request->header(ResolveBotSession::HEADER, ''));
        if (! $botService && $session !== '' && $request->user()) {
            abort_unless(
                $request->user()->company?->whatsapp_session_name === $session,
                403,
                'Este token é de outra loja: a conversa é da sessão "'.$session.'". Use a chave do serviço do bot.'
            );
        }

        return $next($request);
    }
}
