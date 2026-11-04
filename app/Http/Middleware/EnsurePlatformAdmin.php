<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Área admin da plataforma: só as contas de PLATFORM_ADMIN_EMAILS. Pros outros, a rota "não existe". */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatformAdmin(), 404);

        return $next($request);
    }
}
