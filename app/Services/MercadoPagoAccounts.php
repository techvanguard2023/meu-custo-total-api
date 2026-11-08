<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Conta do Mercado Pago de cada loja (botão "Conectar com Mercado Pago").
 *  - connectUrl(): guarda um "state" de uso único (15 min) ligado à loja e devolve o endereço de autorização;
 *  - finish(): na volta, troca o código pelos tokens e grava a conta (uma por loja);
 *  - token(): devolve o acesso válido, renovando quando faltam menos de 30 dias (vale 180);
 *  - em ambiente local, MERCADOPAGO_TEST_ACCESS_TOKEN (conta de teste vendedora) dispensa o OAuth.
 */
class MercadoPagoAccounts
{
    private const STATE_TTL_MINUTES = 15;

    private const REFRESH_BEFORE_DAYS = 30;

    public function __construct(private MercadoPago $api) {}

    public function connectUrl(Company $company, int $userId): string
    {
        if (! MercadoPago::configured()) {
            throw new RuntimeException('O pagamento online ainda não está disponível. Fale com o suporte.');
        }
        $state = Str::random(40);
        Cache::put($this->stateKey($state), ['company_id' => $company->id, 'user_id' => $userId], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $this->api->authorizationUrl($state);
    }

    /** Volta do Mercado Pago. Devolve a loja conectada; erro = RuntimeException com frase para a tela. */
    public function finish(?string $state, ?string $code): Company
    {
        $data = $state ? Cache::pull($this->stateKey($state)) : null;
        if (! $data) {
            throw new RuntimeException('O link de conexão expirou. Clique em "Conectar com Mercado Pago" de novo.');
        }
        $company = Company::find($data['company_id']);
        if (! $company || ! $code) {
            throw new RuntimeException('A conexão não foi autorizada no Mercado Pago.');
        }
        if (! $company->isPro()) {
            throw new RuntimeException('O pagamento online é do plano Pro.');
        }

        try {
            $token = $this->api->exchangeCode($code);
        } catch (\Throwable $e) {
            Log::warning("Mercado Pago: conexão da loja {$company->id} falhou: ".Str::limit($e->getMessage(), 300));
            throw new RuntimeException('O Mercado Pago não confirmou a conexão. Tente de novo.');
        }

        // A mesma conta do Mercado Pago não pode receber por duas lojas (os avisos chegam pelo usuário dela)
        $other = PaymentAccount::where('provider_user_id', (string) $token['user_id'])->where('company_id', '!=', $company->id)->whereNull('revoked_at')->exists();
        if ($other) {
            throw new RuntimeException('Esta conta do Mercado Pago já está conectada a outra loja do Meu Custo Total.');
        }

        PaymentAccount::updateOrCreate(['company_id' => $company->id], [
            'provider' => 'mercadopago',
            'provider_user_id' => (string) $token['user_id'],
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'public_key' => $token['public_key'] ?? null,
            'live_mode' => (bool) ($token['live_mode'] ?? true),
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 15552000)),
            'connected_at' => now(),
            'revoked_at' => null,
            'last_error' => null,
        ]);

        return $company;
    }

    public function account(Company $company): ?PaymentAccount
    {
        return PaymentAccount::where('company_id', $company->id)->first();
    }

    /** Conectada e pronta para cobrar (ou o token de teste local). */
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
            throw new RuntimeException('Conecte a conta do Mercado Pago em Integrações para gerar Pix.');
        }
        if ($account->expires_at && $account->expires_at->lt(now()->addDays(self::REFRESH_BEFORE_DAYS)) && $account->refresh_token) {
            $this->refresh($account);
        }

        return $account->access_token;
    }

    public function refresh(PaymentAccount $account): void
    {
        try {
            $token = $this->api->refresh($account->refresh_token);
            $account->update([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
                'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 15552000)),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $account->update(['last_error' => Str::limit($e->getMessage(), 250)]);
            // Vencido de vez: a loja precisa conectar de novo
            if ($account->expires_at && $account->expires_at->isPast()) {
                $account->update(['revoked_at' => now()]);
            }
            Log::warning("Mercado Pago: renovação do acesso da loja {$account->company_id} falhou: ".Str::limit($e->getMessage(), 300));
        }
    }

    /** Desconectar: a loja deixa de gerar Pix (os Pix já gerados continuam valendo no Mercado Pago). */
    public function disconnect(Company $company): void
    {
        PaymentAccount::where('company_id', $company->id)->delete();
    }

    /** Aviso "mp-connect" (o lojista revogou o acesso no Mercado Pago) ou token recusado (401). */
    public function revokeByProviderUser(string $providerUserId): void
    {
        PaymentAccount::where('provider_user_id', $providerUserId)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'last_error' => 'Acesso revogado no Mercado Pago']);
    }

    private function testToken(): ?string
    {
        $token = config('services.mercadopago.test_access_token');

        return $token && ! app()->isProduction() ? $token : null;
    }

    private function stateKey(string $state): string
    {
        return 'mp-oauth-state:'.$state;
    }
}
