<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/** Tela pública do convite: a pessoa convidada confere de quem é o convite e cria a senha. */
class InvitationController extends Controller
{
    public function show(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string']]);
        $user = $this->pendingUser($data['email'], $data['token']);

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'company' => $user->company?->name,
            'role' => $user->role,
        ]);
    }

    /** Cria a senha, registra o aceite dos termos e já entra no sistema. */
    public function accept(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => [...User::STRONG_PASSWORD, 'confirmed'],
            'accept_terms' => ['accepted'],
        ], [
            ...User::STRONG_PASSWORD_MESSAGES,
            'password.confirmed' => 'A confirmação da senha não confere.',
            'accept_terms.accepted' => 'Para entrar, aceite os Termos de Uso e a Política de Privacidade.',
        ]);

        $user = $this->pendingUser($data['email'], $data['token']);

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'invitation_accepted_at' => now(),
        ])->save();
        $user->acceptTerms($request->ip());
        Password::broker('invites')->deleteToken($user);

        return response()->json([
            'user' => $user->fresh()->load('company'),
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    private function pendingUser(string $email, string $token): User
    {
        $user = User::where('email', $email)->whereNull('invitation_accepted_at')->whereNotNull('invited_at')->first();

        abort_unless(
            $user && Password::broker('invites')->tokenExists($user, $token),
            422,
            'Convite inválido ou vencido. Peça ao responsável pela conta para enviar de novo.'
        );

        return $user;
    }
}
