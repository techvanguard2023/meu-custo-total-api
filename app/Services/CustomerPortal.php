<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Display;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Área "Meus pedidos" do catálogo: o cliente entra só com o telefone + um código de 6 dígitos que chega
 * no WhatsApp da loja (sem senha). Proteções contra abuso — pedir códigos pra números aleatórios poderia
 * fazer o WhatsApp da loja ser bloqueado:
 *  - só envia código pra quem já é cliente da loja; a resposta da tela é sempre a mesma (não revela quem é);
 *  - no máximo 1 código por minuto e 5 por hora por telefone (e limite por IP nas rotas);
 *  - o código vale 10 minutos e aceita 5 tentativas.
 *
 * O mesmo login serve à área do parceiro (dono da loja que tem um expositor), com escopo próprio: o
 * código só vai pro telefone cadastrado num expositor ativo, e a sessão de um escopo não vale no outro.
 */
class CustomerPortal
{
    public const SCOPE_CUSTOMER = 'customer';
    public const SCOPE_PARTNER = 'partner';

    public const CODE_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const SESSION_DAYS = 30;

    public function __construct(private WhatsAppMessenger $messenger) {}

    /** Disponível só pra loja Pro com WhatsApp conectado (é por ele que o código chega). */
    public static function isAvailable(Company $company): bool
    {
        return $company->isPro() && (bool) $company->whatsapp_session_name;
    }

    /** Clientes da loja com esse telefone (pode haver cadastros duplicados em formatos diferentes). */
    public function customerIds(Company $company, string $phoneKey)
    {
        return $company->customers()->whereNotNull('phone')->get(['id', 'phone'])
            ->filter(fn (Customer $c) => Customer::phoneKey($c->phone) === $phoneKey)
            ->pluck('id');
    }

    /** Expositores (não encerrados) cujo telefone de contato é esse — é o que o parceiro enxerga. */
    public function partnerDisplays(Company $company, string $phoneKey)
    {
        return $company->displays()
            ->where('status', '!=', Display::STATUS_ENDED)
            ->whereNotNull('phone')
            ->orderBy('name')
            ->get()
            ->filter(fn (Display $d) => Customer::phoneKey($d->phone) === $phoneKey)
            ->values();
    }

    /** Telefone (como está cadastrado) pra onde mandar o código, ou null se o número não tem acesso. */
    private function recipientPhone(Company $company, string $phoneKey, string $scope): ?string
    {
        if ($scope === self::SCOPE_PARTNER) {
            return $this->partnerDisplays($company, $phoneKey)->first()?->phone;
        }

        $ids = $this->customerIds($company, $phoneKey);

        return $ids->isEmpty() ? null : $company->customers()->whereIn('id', $ids)->orderBy('id')->value('phone');
    }

    /**
     * Envia o código. Retorna null quando deu certo OU quando o número não tem acesso (mesma resposta pra
     * tela); retorna a mensagem de espera quando o telefone pediu códigos demais.
     */
    public function requestCode(Company $company, string $phone, ?string $ip, string $scope = self::SCOPE_CUSTOMER): ?string
    {
        $phoneKey = Customer::phoneKey($phone);
        $recipient = $phoneKey !== null ? $this->recipientPhone($company, $phoneKey, $scope) : null;
        if ($recipient === null) {
            // Mesma resposta na tela; o log ajuda a entender "o código não chegou" (número não cadastrado)
            Log::info("Código de acesso não enviado (empresa {$company->id}): número final ".substr((string) $phoneKey, -4)." não está cadastrado".($scope === self::SCOPE_PARTNER ? ' em nenhum expositor ativo' : ' como cliente'));

            return null;
        }

        $recent = DB::table('customer_login_codes')->where('company_id', $company->id)->where('scope', $scope)->where('phone_key', $phoneKey);
        $last = (clone $recent)->max('created_at');
        if ($last && Carbon::parse($last)->gt(now()->subMinute())) {
            return 'Aguarde um minuto antes de pedir outro código.';
        }
        if ((clone $recent)->where('created_at', '>', now()->subHour())->count() >= 5) {
            return 'Muitos códigos pedidos para esse número. Tente de novo mais tarde.';
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::table('customer_login_codes')->insert([
            'company_id' => $company->id,
            'scope' => $scope,
            'phone_key' => $phoneKey,
            'code_hash' => $this->hashCode($code, $phoneKey),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'ip' => $ip,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $what = $scope === self::SCOPE_PARTNER ? 'à área do parceiro' : 'aos seus pedidos';
        $result = $this->messenger->deliver($company, $recipient, implode("\n", [
            "Seu código de acesso {$what} na {$company->name}: *{$code}*",
            '',
            'Ele vale por '.self::CODE_MINUTES.' minutos. Se não foi você que pediu, é só ignorar esta mensagem.',
        ]));

        // Falha no envio não muda a resposta da tela (senão revelaria que o número é cliente); fica no log
        if (! $result['sent']) {
            Log::warning("Código de acesso não enviado (empresa {$company->id}): {$result['reason']}");
        }

        return null;
    }

    /** Confere o código; certo = cria a sessão e devolve o token (que só o navegador do cliente guarda). */
    public function verifyCode(Company $company, string $phone, string $code, string $scope = self::SCOPE_CUSTOMER): ?string
    {
        $phoneKey = Customer::phoneKey($phone);
        if ($phoneKey === null) {
            return null;
        }

        $row = DB::table('customer_login_codes')
            ->where('company_id', $company->id)
            ->where('scope', $scope)
            ->where('phone_key', $phoneKey)
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $row || $row->attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        if (! hash_equals($row->code_hash, $this->hashCode(preg_replace('/\D/', '', $code), $phoneKey))) {
            DB::table('customer_login_codes')->where('id', $row->id)->increment('attempts');

            return null;
        }

        // Código usado: apaga os dessa pessoa (não dá pra reaproveitar)
        DB::table('customer_login_codes')->where('company_id', $company->id)->where('scope', $scope)->where('phone_key', $phoneKey)->delete();

        $token = Str::random(64);
        DB::table('customer_portal_sessions')->insert([
            'company_id' => $company->id,
            'scope' => $scope,
            'phone_key' => $phoneKey,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::SESSION_DAYS),
            'last_used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    /** Telefone (chave) da sessão, ou null se o token não vale (expirado, de outra loja, inexistente). */
    public function phoneKeyForToken(Company $company, ?string $token, string $scope = self::SCOPE_CUSTOMER): ?string
    {
        if (! $token) {
            return null;
        }

        $session = DB::table('customer_portal_sessions')
            ->where('company_id', $company->id)
            ->where('scope', $scope)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            return null;
        }

        DB::table('customer_portal_sessions')->where('id', $session->id)->update(['last_used_at' => now()]);

        return $session->phone_key;
    }

    public function logout(Company $company, ?string $token): void
    {
        if ($token) {
            DB::table('customer_portal_sessions')->where('company_id', $company->id)->where('token_hash', hash('sha256', $token))->delete();
        }
    }

    private function hashCode(string $code, string $phoneKey): string
    {
        return hash_hmac('sha256', $code.'|'.$phoneKey, (string) config('app.key'));
    }
}
