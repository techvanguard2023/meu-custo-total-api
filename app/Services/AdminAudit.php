<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Registro de toda ação da área admin da plataforma: quem, quando, em qual loja e o que mudou. */
class AdminAudit
{
    public static function log(Request $request, string $action, ?int $companyId = null, ?int $targetUserId = null, array $details = []): void
    {
        self::logAs($request->user()?->id, (string) $request->user()?->email, $action, $companyId, $targetUserId, $details, $request->ip());
    }

    /** Quando quem age não é o usuário da requisição (ex: admin dentro de uma loja pelo "Entrar como"). */
    public static function logAs(?int $adminId, string $adminEmail, string $action, ?int $companyId = null, ?int $targetUserId = null, array $details = [], ?string $ip = null): void
    {
        DB::table('admin_audit_logs')->insert([
            'admin_user_id' => $adminId,
            'admin_email' => $adminEmail,
            'action' => $action,
            'company_id' => $companyId,
            'target_user_id' => $targetUserId,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }

    /** Token do "Entrar como" (nome "impersonation:{id do admin}") → id do admin, ou null se for um login comum. */
    public static function impersonatorId(?object $token): ?int
    {
        $name = (string) ($token->name ?? '');

        return str_starts_with($name, 'impersonation:') ? (int) substr($name, strlen('impersonation:')) : null;
    }
}
