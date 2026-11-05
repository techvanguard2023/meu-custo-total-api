<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /** Atualiza os dados cadastrais do usuário logado. */
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $user->update($data);

        return response()->json([
            'message' => 'Dados atualizados com sucesso!',
            'user' => $user->fresh()->load('company'),
        ]);
    }

    /** Troca a senha exigindo a senha atual. */
    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            // Mesmas regras do cadastro e do convite (User::STRONG_PASSWORD)
            'password' => [...User::STRONG_PASSWORD, 'confirmed'],
        ], [
            'current_password.current_password' => 'A senha atual está incorreta.',
            'password.confirmed' => 'A confirmação da nova senha não confere.',
            ...User::STRONG_PASSWORD_MESSAGES,
        ]);

        $request->user()->update([
            'password' => Hash::make($data['password']),
        ]);

        return response()->json(['message' => 'Senha alterada com sucesso!']);
    }
}
