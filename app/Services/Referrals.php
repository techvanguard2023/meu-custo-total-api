<?php

namespace App\Services;

use App\Models\Company;
use App\Notifications\ReferralRewarded;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * Indique e ganhe (lojas indicando o Meu Custo Total):
 *  - cada loja tem um código; o link é www.meucustototal.com.br/?ref=CODIGO (a landing guarda no cookie mct_src e o
 *    cadastro manda junto; o /register?ref= do app também vale);
 *  - no cadastro, a loja nova fica ligada à que indicou (não vale indicar a si mesma: mesma loja, e-mail ou telefone);
 *  - quando a indicada faz o primeiro pagamento, a que indicou ganha 1 mês de Pro: crédito na Stripe no valor da
 *    mensalidade dela (quem já assina) ou 1 mês de cortesia Pro (quem está no Grátis). Comando referrals:reward.
 */
class Referrals
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem 0/O, 1/I

    /** Indicadas há mais de 6 meses sem pagar não geram mais recompensa. */
    public const REWARD_WINDOW_DAYS = 180;

    public function __construct(private PlatformWhatsApp $whatsapp) {}

    /** Código da loja (cria na primeira vez). */
    public function code(Company $company): string
    {
        if ($company->referral_code) {
            return $company->referral_code;
        }
        do {
            $code = collect(range(1, 6))->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode('');
        } while (Company::where('referral_code', $code)->exists());

        $company->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    public function link(Company $company): string
    {
        return rtrim((string) config('services.landing_url'), '/').'/?ref='.$this->code($company);
    }

    public static function normalizeCode(?string $code): ?string
    {
        $code = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));

        return $code !== '' && strlen($code) <= 12 ? $code : null;
    }

    /** Cadastro novo: liga à loja que indicou, se o código for válido e não for a mesma pessoa. */
    public function attach(Company $new, ?string $code, ?string $email, ?string $phone): ?Company
    {
        $code = self::normalizeCode($code);
        if (! $code || $new->referred_by_company_id) {
            return null;
        }
        $referrer = Company::where('referral_code', $code)->whereKeyNot($new->id)->first();
        if (! $referrer || $referrer->deletion_scheduled_for || $referrer->blocked_at) {
            return null;
        }
        // Mesma pessoa abrindo outra loja não conta
        $digits = fn (?string $p) => substr(preg_replace('/\D/', '', (string) $p), -9);
        $sameOwner = $referrer->users()->get(['email', 'phone'])->contains(fn ($u) => ($email && Str::lower($u->email) === Str::lower($email))
            || ($phone && $digits($phone) !== '' && $digits($u->phone) === $digits($phone)));
        if ($sameOwner) {
            return null;
        }

        $new->forceFill(['referred_by_company_id' => $referrer->id])->save();

        return $referrer;
    }

    /** Indicadas que já pagaram e ainda não renderam a recompensa. Devolve [indicada => recompensa]. */
    public function rewardDue(?Carbon $now = null): array
    {
        $now ??= now();
        $done = [];

        $paid = DB::table('platform_payments')->whereRaw('amount - amount_refunded > 0')->select('company_id');
        Company::whereNotNull('referred_by_company_id')
            ->whereNull('referral_rewarded_at')
            ->where('created_at', '>=', $now->copy()->subDays(self::REWARD_WINDOW_DAYS))
            ->whereIn('id', $paid)
            ->with('referredBy')
            ->each(function (Company $referred) use (&$done) {
                try {
                    if ($reward = $this->reward($referred)) {
                        $done[$referred->id] = $reward;
                    }
                } catch (Throwable $e) {
                    Log::warning("Indicação: recompensa pela loja {$referred->id} falhou (tenta de novo na próxima hora): ".$e->getMessage());
                }
            });

        return $done;
    }

    /** Dá a recompensa à loja que indicou e marca na indicada. */
    public function reward(Company $referred): ?array
    {
        $referrer = $referred->referredBy;
        if (! $referrer || $referrer->deletion_scheduled_for) {
            $referred->forceFill(['referral_rewarded_at' => now(), 'referral_reward' => ['type' => 'none', 'why' => 'loja que indicou não existe mais']])->save();

            return null;
        }

        $subscription = $referrer->subscription('default');
        if ($subscription && $subscription->valid() && ! $subscription->onGracePeriod() && config('cashier.secret')) {
            // Já assina: crédito na Stripe no valor de 1 mensalidade (abate da próxima cobrança)
            $price = Cashier::stripe()->prices->retrieve($subscription->stripe_price);
            $amount = (int) ($price->unit_amount ?? 0);
            if ($amount > 0) {
                $referrer->creditBalance($amount, "Indicação: {$referred->name} assinou (1 mês grátis)");
                $reward = ['type' => 'credit', 'amount' => $amount / 100, 'currency' => $price->currency ?? 'brl'];
            }
        }

        if (empty($reward)) {
            // Grátis (ou sem Stripe): 1 mês de cortesia Pro, somando a uma cortesia Pro que já tenha
            if ($referrer->comp_plan === Company::PLAN_PRO && $referrer->activeCompPlan() && ! $referrer->comp_until) {
                $reward = ['type' => 'none', 'why' => 'já tem cortesia Pro sem prazo'];
            } else {
                $base = $referrer->activeCompPlan() === Company::PLAN_PRO && $referrer->comp_until?->isFuture() ? $referrer->comp_until : now()->startOfDay();
                $until = $base->copy()->addMonth();
                $referrer->forceFill([
                    'comp_plan' => Company::PLAN_PRO,
                    'comp_until' => $until->toDateString(),
                    'comp_note' => trim("Indicação: {$referred->name} assinou (1 mês)"),
                ])->save();
                $referrer->update(['plan' => $referrer->planFromBilling()]);
                $reward = ['type' => 'comp', 'until' => $until->toDateString()];
            }
        }

        $referred->forceFill(['referral_rewarded_at' => now(), 'referral_reward' => $reward])->save();

        if ($reward['type'] !== 'none') {
            $this->notify($referrer, $referred, $reward);
        }

        return $reward;
    }

    /** Resumo pra tela "Indique e ganhe" da loja. */
    public function summary(Company $company): array
    {
        $referred = $company->referrals()->latest('id')->limit(50)->get(['id', 'name', 'created_at', 'referral_rewarded_at', 'referral_reward', 'plan']);
        $paid = DB::table('platform_payments')->whereIn('company_id', $referred->pluck('id'))->whereRaw('amount - amount_refunded > 0')
            ->distinct()->pluck('company_id')->flip();

        return [
            'code' => $this->code($company),
            'link' => $this->link($company),
            'referred' => $referred->map(fn (Company $c) => [
                'name' => $c->name,
                'created_at' => $c->created_at?->toIso8601String(),
                'status' => $paid->has($c->id) ? 'subscribed' : 'signed_up',
                'reward' => $c->referral_reward && ($c->referral_reward['type'] ?? 'none') !== 'none' ? $c->referral_reward : null,
            ])->values(),
            'rewards' => $referred->filter(fn ($c) => ($c->referral_reward['type'] ?? 'none') !== 'none')->count(),
        ];
    }

    private function notify(Company $referrer, Company $referred, array $reward): void
    {
        $owner = $referrer->users()->where('role', 'owner')->orderBy('id')->first();
        if ($owner?->email) {
            try {
                $owner->notify(new ReferralRewarded($referrer->name, $referred->name, $reward));
            } catch (Throwable $e) {
                Log::warning("Indicação: e-mail da recompensa (loja {$referrer->id}) não enviado: ".$e->getMessage());
            }
        }

        // WhatsApp só em horário comercial (o comando roda de hora em hora; fora do horário fica só o e-mail)
        $hour = (int) now('America/Sao_Paulo')->format('G');
        if ($hour >= 9 && $hour < 20) {
            $what = $reward['type'] === 'credit'
                ? 'um crédito de R$ '.number_format($reward['amount'], 2, ',', '.').' na sua próxima mensalidade'
                : '1 mês de Pro grátis (até '.Carbon::parse($reward['until'])->format('d/m').')';
            $this->whatsapp->sendToOwner($referrer, implode("\n", [
                '🎉 *Sua indicação deu certo!*',
                '',
                "A *{$referred->name}* assinou o Meu Custo Total pela sua indicação e você ganhou {$what}.",
                '',
                'Obrigado por indicar! Seu link continua valendo:',
                $this->link($referrer),
                '',
                '— Equipe Meu Custo Total',
            ]));
        }
    }
}
