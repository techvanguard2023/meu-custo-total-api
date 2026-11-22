<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Conta do PagBank de cada loja (botão "Conectar com PagBank", via Connect).
 *  - connectUrl(): guarda um "state" de uso único (15 min) ligado à loja e devolve o endereço de autorização;
 *  - finish(): na volta, troca o código pelos tokens e grava a conta. A loja usa um gateway por vez: conectar o
 *    PagBank substitui a conta do Mercado Pago (e vice-versa) — as cobranças já criadas seguem no gateway delas;
 *  - connectWithToken(): sem o Connect, a loja cola o token da própria conta do PagBank (não vence nem renova;
 *    provider_user_id "token:{hash}" marca o modo e impede a mesma conta em duas lojas);
 *  - token(): devolve o acesso válido, renovando quando faltam menos de 30 dias;
 *  - em ambiente local, PAGBANK_TEST_ACCESS_TOKEN (conta sandbox vendedora) dispensa o Connect.
 */
class PagBankAccounts
{
    public const PROVIDER = 'pagbank';

    private const STATE_TTL_MINUTES = 15;

    private const REFRESH_BEFORE_DAYS = 30;

    /** A doc não diz a unidade nem garante o campo: sem ele, considera um ano e renova antes. */
    private const DEFAULT_TTL_SECONDS = 31536000;

    public function __construct(private PagBank $api) {}

    public function connectUrl(Company $company, int $userId): string
    {
        if (! PagBank::configured()) {
            throw new RuntimeException('O pagamento pelo PagBank ainda não está disponível. Fale com o suporte.');
        }
        $state = Str::random(40);
        Cache::put($this->stateKey($state), ['company_id' => $company->id, 'user_id' => $userId], now()->addMinutes(self::STATE_TTL_MINUTES));

        return $this->api->authorizationUrl($state);
    }

    /** Volta do PagBank. Devolve a loja conectada; erro = RuntimeException com frase para a tela. */
    public function finish(?string $state, ?string $code): Company
    {
        $data = $state ? Cache::pull($this->stateKey($state)) : null;
        if (! $data) {
            throw new RuntimeException('O link de conexão expirou. Clique em "Conectar com PagBank" de novo.');
        }
        $company = Company::find($data['company_id']);
        if (! $company || ! $code) {
            throw new RuntimeException('A conexão não foi autorizada no PagBank.');
        }
        if (! $company->isPro()) {
            throw new RuntimeException('O pagamento online é do plano Pro.');
        }

        try {
            $token = $this->api->exchangeCode($code);
        } catch (\Throwable $e) {
            Log::warning("PagBank: conexão da loja {$company->id} falhou: ".Str::limit($e->getMessage(), 300));
            throw new RuntimeException('O PagBank não confirmou a conexão. Tente de novo.');
        }

        $accountId = (string) ($token['account_id'] ?? '');
        if ($accountId === '') {
            throw new RuntimeException('O PagBank não informou a conta conectada. Tente de novo.');
        }
        $other = PaymentAccount::where('provider', self::PROVIDER)->where('provider_user_id', $accountId)->where('company_id', '!=', $company->id)->whereNull('revoked_at')->exists();
        if ($other) {
            throw new RuntimeException('Esta conta do PagBank já está conectada a outra loja do Meu Custo Total.');
        }

        DB::transaction(fn () => PaymentAccount::updateOrCreate(['company_id' => $company->id], [
            'provider' => self::PROVIDER,
            'provider_user_id' => $accountId,
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'public_key' => null,
            'live_mode' => ! config('services.pagbank.sandbox'),
            'expires_at' => now()->addSeconds($this->ttl($token)),
            'connected_at' => now(),
            'revoked_at' => null,
            'last_error' => null,
        ]));

        return $company;
    }

    /** Conecta pelo token da própria conta do PagBank. Erro = RuntimeException com frase para a tela. */
    public function connectWithToken(Company $company, string $token): PaymentAccount
    {
        if (! $company->isPro()) {
            throw new RuntimeException('O pagamento online é do plano Pro.');
        }
        $token = trim($token);
        if (strlen($token) < 20 || preg_match('/\s/', $token)) {
            throw new RuntimeException('Esse token não parece completo. Copie o token inteiro no painel do PagBank.');
        }

        try {
            $works = $this->api->tokenWorks($token);
        } catch (\Throwable $e) {
            Log::warning("PagBank: conferência do token da loja {$company->id} falhou: ".Str::limit($e->getMessage(), 300));
            throw new RuntimeException('O PagBank não respondeu agora. Tente de novo em instantes.');
        }
        if (! $works) {
            throw new RuntimeException(config('services.pagbank.sandbox')
                ? 'O PagBank recusou o token. Confira se ele é da conta de testes (sandbox) e foi copiado inteiro.'
                : 'O PagBank recusou o token. Confira se ele foi copiado inteiro e se é da conta de produção (não do sandbox).');
        }

        $id = 'token:'.substr(hash('sha256', $token), 0, 32);
        $other = PaymentAccount::where('provider', self::PROVIDER)->where('provider_user_id', $id)->where('company_id', '!=', $company->id)->whereNull('revoked_at')->exists();
        if ($other) {
            throw new RuntimeException('Este token do PagBank já está conectado a outra loja do Meu Custo Total.');
        }

        return DB::transaction(fn () => PaymentAccount::updateOrCreate(['company_id' => $company->id], [
            'provider' => self::PROVIDER,
            'provider_user_id' => $id,
            'access_token' => $token,
            'refresh_token' => null,
            'public_key' => null,
            'live_mode' => ! config('services.pagbank.sandbox'),
            'expires_at' => null,
            'connected_at' => now(),
            'revoked_at' => null,
            'last_error' => null,
        ]));
    }

    /** A conta foi conectada colando o token (e não pelo Connect)? */
    public static function byToken(PaymentAccount $account): bool
    {
        return str_starts_with((string) $account->provider_user_id, 'token:');
    }

    /** A conta do PagBank da loja (null se ela não conectou, ou conectou outro gateway). */
    public function account(Company $company): ?PaymentAccount
    {
        // Conta conectada no outro ambiente (sandbox x produção) não vale aqui: a loja conecta de novo
        return PaymentAccount::where('company_id', $company->id)->where('provider', self::PROVIDER)
            ->where('live_mode', ! config('services.pagbank.sandbox'))->first();
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
            throw new RuntimeException('Conecte a conta do PagBank em Integrações para cobrar online.');
        }
        if ($account->expires_at && $account->expires_at->lt(now()->addDays(self::REFRESH_BEFORE_DAYS)) && $account->refresh_token) {
            $this->refresh($account);
        }

        return $account->fresh()->access_token;
    }

    /** Renova o acesso. Cada renovação invalida o refresh_token anterior: grava o novo antes de qualquer outra coisa. */
    public function refresh(PaymentAccount $account): void
    {
        try {
            $token = $this->api->refresh($account->refresh_token);
            $account->update([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
                'expires_at' => now()->addSeconds($this->ttl($token)),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $account->update(['last_error' => Str::limit($e->getMessage(), 250)]);
            if ($account->expires_at && $account->expires_at->isPast()) {
                $account->update(['revoked_at' => now()]);
            }
            Log::warning("PagBank: renovação do acesso da loja {$account->company_id} falhou: ".Str::limit($e->getMessage(), 300));
        }
    }

    /** Desconectar: a loja deixa de cobrar pelo PagBank (as cobranças já geradas continuam valendo lá). */
    public function disconnect(Company $company): void
    {
        PaymentAccount::where('company_id', $company->id)->where('provider', self::PROVIDER)->delete();
    }

    /** Acesso recusado (401): a loja revogou no PagBank — marca para conectar de novo. */
    public function revoke(Company $company): void
    {
        PaymentAccount::where('company_id', $company->id)->where('provider', self::PROVIDER)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'last_error' => 'Acesso recusado pelo PagBank']);
    }

    private function ttl(array $token): int
    {
        $seconds = (int) ($token['expires_in'] ?? 0);

        return $seconds > 0 ? $seconds : self::DEFAULT_TTL_SECONDS;
    }

    private function testToken(): ?string
    {
        $token = config('services.pagbank.test_access_token');

        return $token && ! app()->isProduction() ? $token : null;
    }

    private function stateKey(string $state): string
    {
        return 'pagbank-oauth-state:'.$state;
    }
}
