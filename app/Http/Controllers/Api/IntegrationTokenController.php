<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tokens de longa duração pra automações externas (ex: um fluxo no n8n)
 * chamarem a API em nome da empresa — sem depender do token de login de
 * ninguém, que pode cair a qualquer momento (troca de senha, logout).
 *
 * São tokens Sanctum normais, só que nomeados pelo lojista e listados à
 * parte do token "api" que o login gera — nenhuma tabela nova.
 */
class IntegrationTokenController extends Controller
{
    public function index(Request $request)
    {
        $userIds = $request->user()->company->users()->pluck('id');

        return PersonalAccessToken::whereIn('tokenable_id', $userIds)
            ->where('tokenable_type', User::class)
            ->where('name', '!=', 'api')
            ->latest()
            ->get(['id', 'name', 'last_used_at', 'created_at']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $token = $request->user()->createToken($data['name']);

        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $token->accessToken->name,
            'token' => $token->plainTextToken,
            'created_at' => $token->accessToken->created_at,
        ], 201);
    }

    public function destroy(Request $request, PersonalAccessToken $integrationToken)
    {
        $userIds = $request->user()->company->users()->pluck('id');

        abort_unless(
            $integrationToken->tokenable_type === User::class && $userIds->contains($integrationToken->tokenable_id),
            403
        );

        $integrationToken->delete();

        return response()->json(null, 204);
    }
}
