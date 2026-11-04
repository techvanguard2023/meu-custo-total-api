<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AdminAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Entrar como" a loja (área admin): toda alteração feita enquanto o admin está dentro da loja fica na
 * auditoria — método, endereço e resultado. Consultas (GET) não são registradas.
 */
class LogImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $adminId = AdminAudit::impersonatorId($request->user()?->currentAccessToken());
        // A saída tem o próprio registro (impersonate.stop)
        if ($adminId && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && ! $request->is('api/v1/impersonation/stop')) {
            AdminAudit::logAs($adminId, (string) User::whereKey($adminId)->value('email'), 'impersonate.request', $request->user()->company_id, $request->user()->id, [
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status' => $response->getStatusCode(),
            ], $request->ip());
        }

        return $response;
    }
}
