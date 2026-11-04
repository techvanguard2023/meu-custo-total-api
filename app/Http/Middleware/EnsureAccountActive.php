<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conta com exclusão agendada fica parada: o painel só deixa baixar os dados ou desfazer a exclusão,
 * e o bot (que usa o token de integração) para de funcionar até lá.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->user()?->company;

        // Loja bloqueada pela plataforma (área admin): o painel e o bot param até desbloquear
        if ($company?->isBlocked()) {
            return response()->json([
                'message' => 'O acesso desta conta está suspenso. Fale com o suporte do Meu Custo Total.',
                'code' => 'account_blocked',
            ], 423);
        }

        if ($company?->isPendingDeletion()) {
            return response()->json([
                'message' => 'Esta conta está com a exclusão agendada. Desfaça a exclusão para voltar a usar o sistema.',
                'code' => 'account_pending_deletion',
                'deletion_scheduled_for' => $company->deletion_scheduled_for->toIso8601String(),
            ], 423);
        }

        return $next($request);
    }
}
