<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Quote;
use Illuminate\Support\Facades\Log;

/**
 * Avisa o cliente pelo WhatsApp quando o pedido muda de coluna no Kanban de
 * produção. Nunca atrapalha quem arrasta o cartão: roda depois da resposta e
 * qualquer falha só vai pro log.
 */
class ProductionStageNotifier
{
    /** Etapas que avisam — "Aguardando Produção" fica de fora: o cliente acabou de ouvir isso na confirmação. */
    public const STAGES = [
        Quote::PRODUCTION_IN_PRODUCTION => 'Em Produção',
        Quote::PRODUCTION_FINISHED => 'Finalizado',
        Quote::PRODUCTION_DELIVERED => 'Entregue ao Cliente',
    ];

    /** Recebimentos que avisam — só quando entra dinheiro (parcial ou total), com os nomes do diálogo de recebimento. */
    public const PAYMENT_EVENTS = [
        'payment_partial' => 'Recebi parte',
        'payment_paid' => 'Recebi tudo',
    ];

    public const PLACEHOLDERS = [
        '{cliente}' => 'Primeiro nome do cliente',
        '{pedido}' => 'Número do pedido (ex: #123)',
        '{itens}' => 'Itens do pedido (ex: 2x Chaveiro, 1x Vaso)',
        '{loja}' => 'Nome da sua empresa',
        '{total}' => 'Valor total do pedido',
        '{recebido}' => 'Quanto já foi recebido',
        '{restante}' => 'Quanto ainda falta receber',
    ];

    private const DEFAULT_MESSAGES = [
        Quote::PRODUCTION_IN_PRODUCTION => 'Olá, {cliente}! Seu pedido *{pedido}* ({itens}) começou a ser produzido aqui na {loja}. Avisamos por aqui quando ficar pronto.',
        Quote::PRODUCTION_FINISHED => 'Olá, {cliente}! Seu pedido *{pedido}* ({itens}) está pronto! Vamos combinar a entrega ou retirada.',
        Quote::PRODUCTION_DELIVERED => 'Olá, {cliente}! Seu pedido *{pedido}* foi entregue. Obrigado por comprar na {loja}! Qualquer dúvida é só chamar.',
        'payment_partial' => 'Olá, {cliente}! Recebemos {recebido} do seu pedido *{pedido}* (total {total}). Ainda falta {restante}. Obrigado!',
        'payment_paid' => 'Olá, {cliente}! Recebemos o pagamento do seu pedido *{pedido}* ({total}). Muito obrigado por comprar na {loja}!',
    ];

    public function __construct(private WahaClient $waha) {}

    /** Configuração efetiva por etapa (o que o lojista salvou por cima do padrão). */
    public static function settingsFor(Company $company): array
    {
        $saved = $company->whatsapp_status_notifications ?? [];

        return collect(self::events())->map(fn ($label, $event) => [
            'label' => $label,
            'group' => array_key_exists($event, self::PAYMENT_EVENTS) ? 'payment' : 'stage',
            'enabled' => (bool) ($saved[$event]['enabled'] ?? true),
            'message' => trim((string) ($saved[$event]['message'] ?? '')) ?: self::DEFAULT_MESSAGES[$event],
        ])->all();
    }

    /** Todos os avisos configuráveis: etapas do Kanban e recebimentos. */
    public static function events(): array
    {
        return self::STAGES + self::PAYMENT_EVENTS;
    }

    public static function defaultMessage(string $stage): string
    {
        return self::DEFAULT_MESSAGES[$stage];
    }

    /** Chamar depois de gravar a mudança de etapa. */
    public function stageChanged(Quote $quote, ?string $from): void
    {
        $stage = $quote->production_status;

        if ($stage === $from || ! array_key_exists($stage, self::STAGES)) {
            return;
        }

        $quoteId = $quote->id;
        dispatch(fn () => app(self::class)->send($quoteId, $stage))->afterResponse();
    }

    /**
     * Chamar depois de gravar um recebimento. Só avisa quando entrou dinheiro
     * (valor recebido subiu) e o pedido ficou parcial ou quitado — cortesia,
     * estorno e regravação do mesmo valor não geram mensagem.
     */
    public function paymentChanged(Quote $quote, float $previousAmount): void
    {
        $event = match ($quote->payment_status) {
            Quote::PAYMENT_PARTIAL => 'payment_partial',
            Quote::PAYMENT_PAID => 'payment_paid',
            default => null,
        };

        if ($event === null || (float) $quote->amount_paid <= $previousAmount) {
            return;
        }

        $quoteId = $quote->id;
        dispatch(fn () => app(self::class)->sendPayment($quoteId, $event))->afterResponse();
    }

    public function sendPayment(int $quoteId, string $event): void
    {
        try {
            $quote = Quote::with(['customer', 'company', 'items'])->find($quoteId);
            $current = match ($quote?->payment_status) {
                Quote::PAYMENT_PARTIAL => 'payment_partial',
                Quote::PAYMENT_PAID => 'payment_paid',
                default => null,
            };

            if ($quote && $current === $event) {
                $this->deliver($quote, $event);
            }
        } catch (\Throwable $e) {
            Log::warning("Aviso de pagamento não enviado (venda {$quoteId}, {$event}): ".$e->getMessage());
        }
    }

    public function send(int $quoteId, string $stage): void
    {
        try {
            $quote = Quote::with(['customer', 'company', 'items'])->find($quoteId);
            if (! $quote || $quote->production_status !== $stage) {
                return;
            }

            if (in_array($stage, $quote->notified_stages ?? [], true)) {
                return;
            }

            if ($this->deliver($quote, $stage)) {
                $quote->forceFill(['notified_stages' => array_values(array_unique([...($quote->notified_stages ?? []), $stage]))])->save();
            }
        } catch (\Throwable $e) {
            Log::warning("Aviso de etapa não enviado (venda {$quoteId}, {$stage}): ".$e->getMessage());
        }
    }

    /** Envia o aviso do evento se tudo permitir (Pro, WhatsApp conectado, telefone, etapa ligada). true = enviou. */
    private function deliver(Quote $quote, string $event): bool
    {
        $company = $quote->company;
        $digits = preg_replace('/\D/', '', (string) $quote->customer?->phone);

        if (! $company?->isPro() || ! $company->whatsapp_session_name || $digits === '' || ! $this->waha->isConfigured()) {
            return false;
        }

        $config = self::settingsFor($company)[$event];
        if (! $config['enabled']) {
            return false;
        }

        $session = $this->waha->getSession($company->whatsapp_session_name);
        if (($session['status'] ?? null) !== 'WORKING') {
            return false;
        }

        $this->waha->sendText($company->whatsapp_session_name, $this->chatId($digits), $this->render($config['message'], $quote, $company));

        return true;
    }

    /** Telefone só com dígitos → id de conversa do WhatsApp (assume DDI 55 quando vem sem). */
    private function chatId(string $digits): string
    {
        return (strlen($digits) <= 11 ? '55'.$digits : $digits).'@c.us';
    }

    private function brl(mixed $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    private function render(string $template, Quote $quote, Company $company): string
    {
        $items = $quote->items->map(fn ($i) => (int) $i->quantity.'x '.$i->description)->implode(', ');

        return strtr($template, [
            '{cliente}' => explode(' ', trim((string) $quote->customer?->name))[0] ?: 'cliente',
            '{pedido}' => '#'.$quote->id,
            '{itens}' => mb_strimwidth($items, 0, 200, '…'),
            '{loja}' => $company->name,
            '{total}' => $this->brl($quote->final_price),
            '{recebido}' => $this->brl($quote->amount_paid),
            '{restante}' => $this->brl($quote->amount_due),
        ]);
    }
}
