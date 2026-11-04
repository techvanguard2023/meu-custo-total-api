<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * "throttle:N,M" com um contador por rota. No Laravel, o contador padrão é só por usuário (ou IP): todas as rotas
 * com throttle somavam no mesmo — ler 20 links bloqueava o "Salvar todos" (10/min), e chamadas comuns podiam
 * travar a abertura de chamado (10 por hora) por até uma hora. Os limitadores com nome (login, cadastro,
 * senha) não passam por aqui: usam a chave que eles mesmos definem.
 */
class ThrottleRequestsPerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $who = $request->user()?->getAuthIdentifier() ?? $request->ip();

        return sha1(implode('|', [$who, $request->method(), $route?->getDomain(), $route?->uri()]));
    }
}
