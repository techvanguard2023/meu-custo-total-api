<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Quote;
use Illuminate\Support\Collection;

/**
 * Cobrança de venda entregue que ainda não foi paga: monta a mensagem com o valor em aberto e a forma de
 * pagamento da loja (chave Pix e/ou link, de Integrações → Pagamento) e manda pelo WhatsApp conectado.
 */
class PaymentReminder
{
    public function __construct(private WhatsAppMessenger $messenger) {}

    /** Venda que pode ser cobrada: aprovada, entregue, não é cortesia e tem valor em aberto. */
    public static function isChargeable(Quote $quote): bool
    {
        return $quote->status === Quote::STATUS_APPROVED
            && $quote->production_status === Quote::PRODUCTION_DELIVERED
            && $quote->amount_due > 0;
    }

    /** Todas as vendas entregues e em aberto do mesmo cliente (só a própria, quando não há cliente). */
    public function openCharges(Quote $quote): Collection
    {
        if (! $quote->customer_id) {
            return collect(self::isChargeable($quote) ? [$quote] : []);
        }

        return Quote::where('company_id', $quote->company_id)
            ->where('customer_id', $quote->customer_id)
            ->where('status', Quote::STATUS_APPROVED)
            ->where('production_status', Quote::PRODUCTION_DELIVERED)
            ->where('is_courtesy', false)
            ->whereColumn('final_price', '>', 'amount_paid')
            ->orderBy('id')
            ->get();
    }

    /**
     * Mensagem da cobrança. A chave Pix vai separada pra o cliente copiar só ela (vale também pro
     * Pix copia e cola); no envio pelo wa.me tudo vai num texto só.
     *
     * @return array{text: string, pix: string|null, total: float}
     */
    public function message(Company $company, Collection $quotes): array
    {
        $customer = $quotes->first()?->customer;
        $firstName = $customer ? explode(' ', trim($customer->name))[0] : null;
        $total = round($quotes->sum(fn (Quote $q) => $q->amount_due), 2);

        $lines = [($firstName ? "Olá, {$firstName}!" : 'Olá!')." Aqui é da {$company->name}."];

        if ($quotes->count() === 1) {
            $quote = $quotes->first();
            $lines[] = "Passando para lembrar do pagamento do seu pedido *#{$quote->id}* ({$this->label($quote)}): *{$this->brl($quote->amount_due)}*"
                .((float) $quote->amount_paid > 0 ? " — valor que falta, já recebemos {$this->brl($quote->amount_paid)}." : '.');
        } else {
            $lines[] = 'Passando para lembrar do pagamento dos seus pedidos:';
            foreach ($quotes as $quote) {
                $lines[] = "• #{$quote->id} — {$this->label($quote)}: {$this->brl($quote->amount_due)}"
                    .((float) $quote->amount_paid > 0 ? ' (valor que falta)' : '');
            }
            $lines[] = "Total: *{$this->brl($total)}*";
        }

        $pix = trim((string) $company->whatsapp_pix_key) ?: null;
        $link = trim((string) $company->whatsapp_payment_link) ?: null;

        if ($pix || $link) {
            $lines[] = '';
            if ($link) {
                $lines[] = "Para pagar pelo link: {$link}";
            }
            if ($pix) {
                $lines[] = $this->isPixCopyPaste($pix)
                    ? 'Para pagar com Pix, use o código copia e cola que vem na próxima mensagem.'
                    : 'Para pagar com Pix, a chave vem na próxima mensagem.';
            }
        }

        $lines[] = '';
        $lines[] = 'Se já pagou, pode desconsiderar esta mensagem. Obrigado!';

        return ['text' => implode("\n", $lines), 'pix' => $pix, 'total' => $total];
    }

    /** Texto único (wa.me ou cópia): a chave Pix entra no fim, em vez de numa mensagem separada. */
    public function singleText(array $message): string
    {
        if (! $message['pix']) {
            return $message['text'];
        }

        return str_replace(
            ['use o código copia e cola que vem na próxima mensagem.', 'a chave vem na próxima mensagem.'],
            ['use o código copia e cola abaixo.', 'use a chave abaixo.'],
            $message['text']
        )."\n\n".$message['pix'];
    }

    /** @return array{sent: bool, reason: string|null} */
    public function send(Company $company, ?string $phone, array $message): array
    {
        $result = $this->messenger->deliver($company, $phone, $message['text']);

        if ($result['sent'] && $message['pix']) {
            $this->messenger->deliver($company, $phone, $message['pix']);
        }

        return $result;
    }

    /** Nome do pedido como o cliente reconhece (sem o JSON interno que alguns pedidos guardam no nome). */
    private function label(Quote $quote): string
    {
        return trim(explode('|__JSON__|', (string) $quote->name)[0]) ?: "Pedido #{$quote->id}";
    }

    private function isPixCopyPaste(string $pix): bool
    {
        return str_starts_with($pix, '000201');
    }

    private function brl(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }
}
