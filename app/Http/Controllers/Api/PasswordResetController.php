<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    /**
     * Envia o link de redefinição por e-mail.
     * Resposta sempre genérica para não revelar se o e-mail existe.
     */
    public function forgot(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            abort(429, 'Aguarde alguns instantes antes de solicitar um novo link.');
        }

        return response()->json([
            'message' => 'Se este e-mail estiver cadastrado, você receberá um link para redefinir a senha.',
        ]);
    }

    /** Redefine a senha a partir do token recebido por e-mail. */
    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.confirmed' => 'A confirmação da nova senha não confere.',
            'password.min' => 'A nova senha deve ter pelo menos 8 caracteres.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->update(['password' => Hash::make($password)]);
                // Invalida sessões de API antigas por segurança
                $user->tokens()->delete();
            }
        );

        abort_unless(
            $status === Password::PASSWORD_RESET,
            422,
            'Link inválido ou expirado. Solicite uma nova redefinição de senha.'
        );

        return response()->json([
            'message' => 'Senha redefinida com sucesso! Faça login com a nova senha.',
        ]);
    }
}
