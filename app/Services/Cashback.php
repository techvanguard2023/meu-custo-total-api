<?php

namespace App\Services;

use App\Models\CashbackEntry;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cashback do cliente (Pro): uma parte do que ele paga volta em crédito para a próxima compra.
 *  - ganha quando o pedido está entregue E pago (cortesia, marketplace e expositor ficam de fora);
 *  - cada crédito vence em N meses; o uso consome primeiro o que vence antes;
 *  - no pedido, cobre até X% do valor e não vale para item já em promoção (o cálculo fica no
 *    QuoteCalculatorService); o saldo só sai de fato quando a venda é aprovada;
 *  - cancelar a venda devolve o que foi usado e estorna o que ela gerou.
 */
class Cashback
{
    public function __construct(private WhatsAppMessenger $messenger) {}

    public static function isActive(Company $company): bool
    {
        return $company->isPro() && (bool) $company->setting?->cashback_enabled;
    }

    /** Saldo que pode ser usado agora (créditos ainda não vencidos). */
    public function balance(Customer $customer): float
    {
        return round((float) CashbackEntry::where('customer_id', $customer->id)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->sum('remaining'), 2);
    }

    /** Saldo somado de vários cadastros (o mesmo telefone pode ter cadastros duplicados). */
    public function balanceForCustomers(Collection $customerIds): float
    {
        return round((float) CashbackEntry::whereIn('customer_id', $customerIds)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->sum('remaining'), 2);
    }

    /** Pedido de quem pode participar: cliente identificado, venda direta (sem marketplace nem expositor), sem cortesia. */
    public static function eligibleOrder(Quote $quote): bool
    {
        return $quote->customer_id !== null
            && $quote->sales_channel_id === null
            && $quote->display_id === null
            && ! $quote->is_courtesy;
    }

    /**
     * Pedido entregue e pago: credita o cashback da compra, uma vez só. Chamado pelos eventos do Quote
     * (qualquer caminho que entregue ou quite a venda passa por lá).
     */
    public function creditPurchase(Quote $quote): void
    {
        $company = $quote->company;
        if (! $company || ! self::isActive($company)) {
            return;
        }

        if ($quote->status !== Quote::STATUS_APPROVED
            || $quote->production_status !== Quote::PRODUCTION_DELIVERED
            || $quote->paid_at === null
            || ! self::eligibleOrder($quote)) {
            return;
        }

        if (CashbackEntry::where('quote_id', $quote->id)->where('type', CashbackEntry::TYPE_PURCHASE)->exists()) {
            return;
        }

        $setting = $company->setting;
        $amount = round((float) $quote->final_price * (float) $setting->cashback_purchase_percent / 100, 2);
        if ($amount <= 0) {
            return;
        }

        $entry = CashbackEntry::create([
            'company_id' => $company->id,
            'customer_id' => $quote->customer_id,
            'type' => CashbackEntry::TYPE_PURCHASE,
            'amount' => $amount,
            'remaining' => $amount,
            'expires_at' => now()->addMonths(max(1, (int) $setting->cashback_validity_months)),
            'quote_id' => $quote->id,
            'note' => "Compra #{$quote->id}",
        ]);

        $referral = $this->rewardReferral($quote);

        // Avisa o cliente (e quem indicou) pelo WhatsApp da loja — depois de gravar tudo, sem nunca derrubar a venda
        DB::afterCommit(function () use ($company, $quote, $entry, $referral) {
            try {
                $this->notifyCredit($company, $quote->customer, $entry);
                if ($referral) {
                    $this->notifyReferrer($company, $referral, $quote->customer);
                }
            } catch (\Throwable $e) {
                Log::warning("Aviso de cashback não enviado (pedido {$quote->id}): ".$e->getMessage());
            }
        });
    }

    /* ---------- Indicação ---------- */

    /** Código do link de indicação do cliente (gerado na primeira vez). Sem letras/números ambíguos. */
    public function referralCode(Customer $customer): string
    {
        if ($customer->referral_code) {
            return $customer->referral_code;
        }

        $first = Str::upper(Str::ascii(explode(' ', trim($customer->name))[0] ?? ''));
        $prefix = substr(preg_replace('/[^A-Z]/', '', $first), 0, 6) ?: 'AMIGO';

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $prefix.random_int(10, 99).($attempt > 5 ? random_int(0, 9) : '');
            if (! Customer::where('company_id', $customer->company_id)->where('referral_code', $code)->exists()) {
                $customer->forceFill(['referral_code' => $code])->saveQuietly();

                return $code;
            }
        }

        throw new \RuntimeException('Não foi possível gerar o código de indicação.');
    }

    /** Link do catálogo com o código de indicação do cliente. */
    public function referralLink(Customer $customer): string
    {
        return $customer->company->catalogUrl().'?indicacao='.$this->referralCode($customer);
    }

    public function findReferrer(Company $company, ?string $code): ?Customer
    {
        $code = Str::upper(trim((string) $code));

        return $code === '' ? null : $company->customers()->where('referral_code', $code)->first();
    }

    /** Já comprou de verdade (venda entregue e paga, não cancelada)? Indicação só vale pra cliente novo. */
    public function hasCompletedPurchase(Customer $customer): bool
    {
        return Quote::where('customer_id', $customer->id)
            ->where('status', Quote::STATUS_APPROVED)
            ->where('production_status', Quote::PRODUCTION_DELIVERED)
            ->whereNotNull('paid_at')
            ->exists();
    }

    /**
     * Liga o cliente a quem indicou. Recusa (devolve o motivo) quando: programa desligado, código de
     * outra pessoa inexistente, o próprio cliente ou o mesmo telefone, já indicado, ou já é cliente
     * que comprou. Devolve null quando deu certo.
     */
    public function attachReferral(Customer $customer, ?Customer $referrer): ?string
    {
        if (! self::isActive($customer->company)) {
            return 'O cashback não está ligado.';
        }
        if (! $referrer || $referrer->company_id !== $customer->company_id) {
            return 'Indicação não encontrada.';
        }
        if ($referrer->id === $customer->id || Customer::phoneKey($referrer->phone) === Customer::phoneKey($customer->phone)) {
            return 'Ninguém pode indicar a si mesmo.';
        }
        if ($customer->referred_by_customer_id || $customer->referral_rewarded_at) {
            return 'Este cliente já tem uma indicação registrada.';
        }
        if ($this->hasCompletedPurchase($customer)) {
            return 'A indicação só vale para quem ainda não comprou na loja.';
        }

        $customer->forceFill(['referred_by_customer_id' => $referrer->id])->save();

        return null;
    }

    /**
     * 1ª compra (entregue e paga) de quem foi indicado: credita quem indicou e dá o bônus de boas-vindas
     * ao indicado — uma vez só. Devolve o crédito de quem indicou (pra avisar), ou null.
     */
    private function rewardReferral(Quote $quote): ?CashbackEntry
    {
        $customer = $quote->customer;
        if (! $customer?->referred_by_customer_id || $customer->referral_rewarded_at) {
            return null;
        }

        $referrer = $customer->referrer;
        $setting = $quote->company->setting;
        $amount = round((float) $quote->final_price * (float) $setting->cashback_referral_percent / 100, 2);
        if (! $referrer || $amount <= 0) {
            return null;
        }

        $expires = now()->addMonths(max(1, (int) $setting->cashback_validity_months));
        $customer->forceFill(['referral_rewarded_at' => now()])->save();

        CashbackEntry::create([
            'company_id' => $quote->company_id,
            'customer_id' => $customer->id,
            'type' => CashbackEntry::TYPE_REFERRAL_WELCOME,
            'amount' => $amount,
            'remaining' => $amount,
            'expires_at' => $expires,
            'quote_id' => $quote->id,
            'note' => 'Bônus de boas-vindas (indicação de '.explode(' ', trim($referrer->name))[0].')',
        ]);

        return CashbackEntry::create([
            'company_id' => $quote->company_id,
            'customer_id' => $referrer->id,
            'type' => CashbackEntry::TYPE_REFERRAL,
            'amount' => $amount,
            'remaining' => $amount,
            'expires_at' => $expires,
            'quote_id' => $quote->id,
            'note' => 'Indicação: '.explode(' ', trim($customer->name))[0].' fez a primeira compra',
        ]);
    }

    /** Quanto o cliente ganha por avaliar esta compra (0 = não ganha: programa desligado, pedido de fora ou já ganhou). */
    public function reviewReward(Quote $quote): float
    {
        $company = $quote->company;
        if (! $company || ! self::isActive($company)
            || $quote->status !== Quote::STATUS_APPROVED
            || ! self::eligibleOrder($quote)
            || CashbackEntry::where('quote_id', $quote->id)->where('type', CashbackEntry::TYPE_REVIEW)->exists()) {
            return 0.0;
        }

        return round((float) $quote->final_price * (float) $company->setting->cashback_review_percent / 100, 2);
    }

    /**
     * Cliente avaliou a compra: credita o cashback da avaliação, uma vez por pedido — qualquer que seja a
     * nota (o prêmio é por avaliar, nunca por elogiar). Devolve quanto creditou.
     */
    public function creditReview(Quote $quote): float
    {
        $amount = $this->reviewReward($quote);
        if ($amount <= 0) {
            return 0.0;
        }

        CashbackEntry::create([
            'company_id' => $quote->company_id,
            'customer_id' => $quote->customer_id,
            'type' => CashbackEntry::TYPE_REVIEW,
            'amount' => $amount,
            'remaining' => $amount,
            'expires_at' => now()->addMonths(max(1, (int) $quote->company->setting->cashback_validity_months)),
            'quote_id' => $quote->id,
            'note' => "Avaliação do pedido #{$quote->id}",
        ]);

        return $amount;
    }

    /**
     * Venda aprovada com cashback: tira do saldo do cliente, consumindo primeiro o que vence antes.
     * Deve rodar dentro de uma transação.
     */
    public function redeem(Quote $quote): void
    {
        $amount = round((float) $quote->cashback_amount, 2);
        if ($amount <= 0) {
            return;
        }

        abort_unless($quote->customer_id, 422, 'Cashback só pode ser usado em pedido com cliente.');

        $credits = CashbackEntry::where('customer_id', $quote->customer_id)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = round((float) $credits->sum('remaining'), 2);
        abort_if(
            $available + 0.001 < $amount,
            422,
            'O cliente não tem mais saldo de cashback suficiente (saldo: R$ '.number_format($available, 2, ',', '.').'). Edite o pedido e tente de novo.'
        );

        $left = $amount;
        foreach ($credits as $credit) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, (float) $credit->remaining);
            $credit->update(['remaining' => round((float) $credit->remaining - $take, 2)]);
            $left = round($left - $take, 2);
        }

        CashbackEntry::create([
            'company_id' => $quote->company_id,
            'customer_id' => $quote->customer_id,
            'type' => CashbackEntry::TYPE_REDEEM,
            'amount' => -$amount,
            'quote_id' => $quote->id,
            'note' => "Usado no pedido #{$quote->id}",
        ]);
    }

    /**
     * Venda cancelada: devolve o cashback usado nela (como crédito novo, com validade cheia) e estorna o
     * que ela gerou — só o que ainda não foi gasto. Deve rodar dentro de uma transação.
     */
    public function reverseForCancelledQuote(Quote $quote): void
    {
        $setting = $quote->company?->setting;

        $used = CashbackEntry::where('quote_id', $quote->id)->where('type', CashbackEntry::TYPE_REDEEM)->sum('amount');
        $refunded = CashbackEntry::where('quote_id', $quote->id)->where('type', CashbackEntry::TYPE_REDEEM_REFUND)->sum('amount');
        $toRefund = round(abs((float) $used) - (float) $refunded, 2);

        if ($toRefund > 0) {
            CashbackEntry::create([
                'company_id' => $quote->company_id,
                'customer_id' => $quote->customer_id,
                'type' => CashbackEntry::TYPE_REDEEM_REFUND,
                'amount' => $toRefund,
                'remaining' => $toRefund,
                'expires_at' => now()->addMonths(max(1, (int) ($setting?->cashback_validity_months ?? 6))),
                'quote_id' => $quote->id,
                'note' => "Pedido #{$quote->id} cancelado — cashback devolvido",
            ]);
        }

        $earned = CashbackEntry::where('quote_id', $quote->id)
            ->whereIn('type', [CashbackEntry::TYPE_PURCHASE, CashbackEntry::TYPE_REVIEW, CashbackEntry::TYPE_REFERRAL, CashbackEntry::TYPE_REFERRAL_WELCOME])
            ->where('remaining', '>', 0)
            ->lockForUpdate()
            ->get();

        // A 1ª compra que premiou a indicação foi cancelada: a próxima compra do indicado pode premiar de novo
        if (CashbackEntry::where('quote_id', $quote->id)->where('type', CashbackEntry::TYPE_REFERRAL)->exists()) {
            $quote->customer?->forceFill(['referral_rewarded_at' => null])->save();
        }

        foreach ($earned as $credit) {
            $left = (float) $credit->remaining;
            $credit->update(['remaining' => 0]);
            CashbackEntry::create([
                'company_id' => $credit->company_id,
                'customer_id' => $credit->customer_id,
                'type' => CashbackEntry::TYPE_REVERSAL,
                'amount' => -$left,
                'quote_id' => $quote->id,
                'note' => "Pedido #{$quote->id} cancelado — cashback da compra estornado",
            ]);
        }
    }

    /** Ajuste manual da loja (presente, correção). Positivo vira crédito com validade; negativo consome o saldo. */
    public function adjust(Customer $customer, float $amount, ?string $note, ?int $userId): void
    {
        DB::transaction(function () use ($customer, $amount, $note, $userId) {
            $setting = $customer->company->setting;
            $amount = round($amount, 2);

            if ($amount > 0) {
                CashbackEntry::create([
                    'company_id' => $customer->company_id,
                    'customer_id' => $customer->id,
                    'type' => CashbackEntry::TYPE_ADJUST,
                    'amount' => $amount,
                    'remaining' => $amount,
                    'expires_at' => now()->addMonths(max(1, (int) $setting->cashback_validity_months)),
                    'user_id' => $userId,
                    'note' => $note ?: 'Crédito manual',
                ]);

                return;
            }

            $credits = CashbackEntry::where('customer_id', $customer->id)
                ->where('remaining', '>', 0)
                ->where('expires_at', '>', now())
                ->orderBy('expires_at')
                ->lockForUpdate()
                ->get();

            $take = min(abs($amount), round((float) $credits->sum('remaining'), 2));
            abort_if($take <= 0, 422, 'O cliente não tem saldo para descontar.');

            $left = $take;
            foreach ($credits as $credit) {
                if ($left <= 0) {
                    break;
                }
                $part = min($left, (float) $credit->remaining);
                $credit->update(['remaining' => round((float) $credit->remaining - $part, 2)]);
                $left = round($left - $part, 2);
            }

            CashbackEntry::create([
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'type' => CashbackEntry::TYPE_ADJUST,
                'amount' => -$take,
                'user_id' => $userId,
                'note' => $note ?: 'Débito manual',
            ]);
        });
    }

    /** Créditos vencidos: zera o que sobrou e registra no extrato. Roda todo dia (agenda). */
    public function expireDue(): int
    {
        $count = 0;

        CashbackEntry::where('remaining', '>', 0)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($credits) use (&$count) {
                foreach ($credits as $credit) {
                    DB::transaction(function () use ($credit) {
                        $left = (float) $credit->remaining;
                        $credit->update(['remaining' => 0]);
                        CashbackEntry::create([
                            'company_id' => $credit->company_id,
                            'customer_id' => $credit->customer_id,
                            'type' => CashbackEntry::TYPE_EXPIRE,
                            'amount' => -$left,
                            'note' => 'Venceu sem ser usado',
                        ]);
                    });
                    $count++;
                }
            });

        return $count;
    }

    private function notifyReferrer(Company $company, CashbackEntry $entry, ?Customer $referred): void
    {
        $referrer = $entry->customer;
        if (! $referrer?->phone) {
            return;
        }

        $first = explode(' ', trim($referrer->name))[0];
        $friend = $referred ? explode(' ', trim($referred->name))[0] : 'Sua indicação';
        $money = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');

        $this->messenger->deliver($company, $referrer->phone, implode("\n", [
            'Oi'.($first !== '' ? ", {$first}" : '')."! {$friend} fez a primeira compra na {$company->name} pela sua indicação 🎉",
            '',
            "Você ganhou *{$money((float) $entry->amount)} de cashback*. Seu saldo agora é de {$money($this->balance($referrer))}.",
            'Continue indicando: '.$this->referralLink($referrer),
        ]));
    }

    private function notifyCredit(Company $company, ?Customer $customer, CashbackEntry $entry): void
    {
        if (! $customer?->phone) {
            return;
        }

        $first = explode(' ', trim($customer->name))[0];
        $money = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $balance = $this->balance($customer);

        $this->messenger->deliver($company, $customer->phone, implode("\n", [
            'Oi'.($first !== '' ? ", {$first}" : '')."! Você ganhou *{$money((float) $entry->amount)} de cashback* na {$company->name} 🎉",
            '',
            "Seu saldo agora é de {$money($balance)} para usar na próxima compra (até ".(int) $company->setting->cashback_max_redeem_percent.'% do valor do pedido).',
            'Esse crédito vale até '.$entry->expires_at->timezone($company->timezone ?: 'America/Sao_Paulo')->format('d/m/Y').'.',
        ]));
    }
}
