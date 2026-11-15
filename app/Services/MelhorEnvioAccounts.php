<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ShippingAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Conta do Melhor Envio de cada loja (botão "Conectar com Melhor Envio"), no mesmo desenho do Mercado Pago:
 * "state" de uso único (15 min) ligado à loja, troca do código por tokens na volta e renovação perto de vencer
 * (o acesso vale 30 dias, o refresh 45). Em ambiente local, MELHORENVIO_TEST_ACCESS_TOKEN dispensa o OAuth.
 */
class MelhorEnvioAccounts
{
    private const STATE_TTL_MINUTES = 15;

    private const REFRESH_BEFORE_DAYS = 7;

    public function __construct(private MelhorEnvio $api) {}

    public function connectUrl(Company $company, int $userId): string
    {
        if (! MelhorEnvio::configured()) {
            throw new RuntimeException('O frete pelo Melhor Envio ainda não está disponível. Fale com o suporte.');
        }
        $state = Str::random(40);
        Cache::put($this->stateKey($state), ['company_id' => $company->id, 'user_id' => $userId], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $this->api->authorizationUrl($state);
    }

    public function finish(?string $state, ?string $code): Company
    {
        $data = $state ? Cache::pull($this->stateKey($state)) : null;
        if (! $data) {
            throw new RuntimeException('O link de conexão expirou. Clique em "Conectar com Melhor Envio" de novo.');
        }
        $company = Company::find($data['company_id']);
        if (! $company || ! $code) {
            throw new RuntimeException('A conexão não foi autorizada no Melhor Envio.');
        }
        if (! $company->hasEssential()) {
            throw new RuntimeException('O frete pelo Melhor Envio é dos planos Essencial e Pro.');
        }

        try {
            $token = $this->api->exchangeCode($code);
        } catch (\Throwable $e) {
            Log::warning("Melhor Envio: conexão da loja {$company->id} falhou: ".Str::limit($e->getMessage(), 300));
            throw new RuntimeException('O Melhor Envio não confirmou a conexão. Tente de novo.');
        }

        ShippingAccount::updateOrCreate(['company_id' => $company->id], [
            'provider' => 'melhorenvio',
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 2592000)),
            'connected_at' => now(),
            'revoked_at' => null,
            'last_error' => null,
        ]);

        return $company;
    }

    public function account(Company $company): ?ShippingAccount
    {
        return ShippingAccount::where('company_id', $company->id)->first();
    }

    public function ready(Company $company): bool
    {
        return $this->testToken() !== null || (bool) $this->account($company)?->isActive();
    }

    /** Acesso válido da loja; renova perto de vencer. Sem conta ativa = RuntimeException com frase para a tela. */
    public function token(Company $company): string
    {
        if ($test = $this->testToken()) {
            return $test;
        }
        $account = $this->account($company);
        if (! $account || ! $account->isActive()) {
            throw new RuntimeException('Conecte a conta do Melhor Envio em Integrações para calcular o frete.');
        }
        if ($account->expires_at && $account->expires_at->lt(now()->addDays(self::REFRESH_BEFORE_DAYS)) && $account->refresh_token) {
            $this->refresh($account);
            $account->refresh();
        }

        return $account->access_token;
    }

    public function refresh(ShippingAccount $account): void
    {
        try {
            $token = $this->api->refresh($account->refresh_token);
            $account->update([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
                'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 2592000)),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $account->update(['last_error' => Str::limit($e->getMessage(), 250)]);
            if ($account->expires_at && $account->expires_at->isPast()) {
                $account->update(['revoked_at' => now()]);
            }
            Log::warning("Melhor Envio: renovação do acesso da loja {$account->company_id} falhou: ".Str::limit($e->getMessage(), 300));
        }
    }

    public function disconnect(Company $company): void
    {
        ShippingAccount::where('company_id', $company->id)->delete();
    }

    /** Acesso recusado pela API (401): a loja precisa conectar de novo. */
    public function revoke(Company $company): void
    {
        ShippingAccount::where('company_id', $company->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'last_error' => 'Acesso recusado pelo Melhor Envio']);
    }

    private function testToken(): ?string
    {
        $token = config('services.melhorenvio.test_access_token');

        return $token && ! app()->isProduction() ? $token : null;
    }

    private function stateKey(string $state): string
    {
        return 'me-oauth-state:'.$state;
    }
}
