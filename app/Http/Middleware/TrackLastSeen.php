<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Último uso do painel (pra "lojas ativas" e o último acesso na área admin). Grava no máximo a cada 5 min
 * e só pra quem entrou pelo login — o bot usa token de integração e não conta como acesso da pessoa.
 */
class TrackLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken()?->name === 'api'
            && (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(5)))) {
            DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now()]);
        }

        return $next($request);
    }
}
