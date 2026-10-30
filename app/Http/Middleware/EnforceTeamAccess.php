<?php

namespace App\Http\Middleware;

use App\Services\TeamAccess;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Perfis da equipe: barra o que o perfil não acessa e tira custos (e, na produção, preços) das respostas.
 * Também segura quem ficou fora do limite de pessoas do plano.
 */
class EnforceTeamAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->isOwner()) {
            return $next($request);
        }

        $uri = $request->route()?->uri() ?? '';

        if ($user->isBlockedByPlanLimit() && ! in_array($uri, ['api/v1/me', 'api/v1/logout'], true)) {
            return response()->json([
                'message' => 'Seu acesso está pausado: o plano da empresa não comporta mais pessoas na equipe. Fale com o responsável pela conta.',
                'code' => 'team_plan_limit',
            ], 403);
        }

        if (! TeamAccess::allows($user, $request->method(), $uri)) {
            return response()->json([
                'message' => 'Seu perfil não tem acesso a esta área. Fale com o responsável pela conta.',
                'code' => 'role_forbidden',
            ], 403);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setData(TeamAccess::filter($response->getData(true), $user));
        }

        return $response;
    }
}
