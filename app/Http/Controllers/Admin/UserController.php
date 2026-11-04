<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/** Usuários de todas as lojas: busca e link de nova senha (pra mandar pelo suporte). */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        return User::query()
            ->with('company:id,name,plan')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderByRaw('last_seen_at IS NULL, last_seen_at DESC')
            ->paginate(25)
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'company' => $u->company ? ['id' => $u->company->id, 'name' => $u->company->name, 'plan' => $u->company->plan] : null,
                'last_seen_at' => $u->last_seen_at?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
                'is_platform_admin' => $u->isPlatformAdmin(),
            ]);
    }

    /** Gera um link de nova senha (vale 60 min) pra o suporte mandar à pessoa — não envia e-mail. */
    public function passwordLink(Request $request, User $user)
    {
        $token = Password::broker()->createToken($user);
        $url = rtrim((string) config('services.frontend_url'), '/').'/reset-password?token='.$token.'&email='.urlencode($user->email);
        AdminAudit::log($request, 'user.password_link', $user->company_id, $user->id, ['email' => $user->email]);

        return response()->json(['url' => $url, 'expires_minutes' => (int) config('auth.passwords.users.expire', 60)]);
    }
}
