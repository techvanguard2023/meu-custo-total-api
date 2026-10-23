<?php

namespace App\Services;

use App\Models\CashbackEntry;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // Avisa o cliente pelo WhatsApp da loja — depois de gravar tudo, e sem nunca derrubar a venda
        DB::afterCommit(function () use ($company, $quote, $entry) {
            try {
                $this->notifyCredit($company, $quote->customer, $entry);
            } catch (\Throwable $e) {
                Log::warning("Aviso de cashback não enviado (pedido {$quote->id}): ".$e->getMessage());
            }
        });
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
            ->where('type', CashbackEntry::TYPE_PURCHASE)
            ->where('remaining', '>', 0)
            ->lockForUpdate()
            ->get();

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
