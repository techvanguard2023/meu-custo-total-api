<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'min:3', 'max:255', 'regex:/\p{L}/u'],
            'name' => ['required', 'string', 'min:3', 'max:255', 'regex:/\p{L}/u'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => User::STRONG_PASSWORD,
            'segment' => ['nullable', Rule::in(Company::SEGMENTS)],
            'accept_terms' => ['accepted'],
        ], [
            'accept_terms.accepted' => 'Para criar a conta, aceite os Termos de Uso e a Política de Privacidade.',
            'email.unique' => 'Este e-mail já está cadastrado. Entre com ele ou use "Esqueci a senha".',
            'company_name.min' => 'O nome da empresa deve ter pelo menos 3 caracteres.',
            'company_name.regex' => 'Informe um nome de empresa válido.',
            'name.min' => 'O nome deve ter pelo menos 3 caracteres.',
            'name.regex' => 'Informe um nome válido.',
            ...User::STRONG_PASSWORD_MESSAGES,
        ]);

        // Tudo ou nada: se algo falhar no meio, não sobra empresa sem usuário
        try {
            $user = $this->createAccount($data, $request);
        } catch (UniqueConstraintViolationException) {
            // Dois cadastros simultâneos com o mesmo e-mail (ou clique duplo): os dois passam pela validação e o
            // segundo esbarra no índice único — a transação desfaz a empresa e a resposta é a mesma da validação
            throw ValidationException::withMessages(['email' => ['Este e-mail já está cadastrado. Entre com ele ou use "Esqueci a senha".']]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->load('company'),
            'token' => $token,
        ], 201);
    }

    private function createAccount(array $data, Request $request): User
    {
        return DB::transaction(function () use ($data, $request) {
            $company = Company::create([
                'name' => $data['company_name'],
                'slug' => Str::slug($data['company_name']).'-'.Str::random(6),
                'segment' => $data['segment'] ?? Company::SEGMENT_3D,
            ]);

            Setting::create(['company_id' => $company->id]);
            // Teste grátis do Pro (sem cartão): entra já vendo tudo o que o Pro faz
            $company->startProTrial((int) config('services.platform.pro_trial_days'), 'signup');

            $user = User::create([
                'company_id' => $company->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => Hash::make($data['password']),
            ]);
            $user->acceptTerms($request->ip());

            return $user;
        });
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        $user = User::where('email', $data['email'])->firstOrFail();
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->load('company'),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout realizado.']);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('company');

        // "Entrar como" (área admin): o app mostra a faixa "Você está acessando como…" e o botão de sair
        $adminId = \App\Services\AdminAudit::impersonatorId($request->user()->currentAccessToken());
        if ($adminId) {
            $user->setAttribute('impersonated_by', [
                'email' => \App\Models\User::whereKey($adminId)->value('email'),
                'expires_at' => $request->user()->currentAccessToken()->expires_at?->toIso8601String(),
            ]);
        }

        return $user;
    }

    /** Sai do "Entrar como": apaga o token da loja (o app volta pro token do admin, guardado no navegador). */
    public function stopImpersonation(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        $adminId = \App\Services\AdminAudit::impersonatorId($token);
        abort_unless($adminId, 422, 'Esta sessão não é um acesso pela área admin.');

        \App\Services\AdminAudit::logAs($adminId, (string) \App\Models\User::whereKey($adminId)->value('email'), 'impersonate.stop', $request->user()->company_id, $request->user()->id, [], $request->ip());
        $token->delete();

        return response()->noContent();
    }
}
