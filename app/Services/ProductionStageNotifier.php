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

    public const PLACEHOLDERS = [
        '{cliente}' => 'Primeiro nome do cliente',
        '{pedido}' => 'Número do pedido (ex: #123)',
        '{itens}' => 'Itens do pedido (ex: 2x Chaveiro, 1x Vaso)',
        '{loja}' => 'Nome da sua empresa',
    ];

    private const DEFAULT_MESSAGES = [
        Quote::PRODUCTION_IN_PRODUCTION => 'Olá, {cliente}! Seu pedido *{pedido}* ({itens}) começou a ser produzido aqui na {loja}. Avisamos por aqui quando ficar pronto.',
        Quote::PRODUCTION_FINISHED => 'Olá, {cliente}! Seu pedido *{pedido}* ({itens}) está pronto! Vamos combinar a entrega ou retirada.',
        Quote::PRODUCTION_DELIVERED => 'Olá, {cliente}! Seu pedido *{pedido}* foi entregue. Obrigado por comprar na {loja}! Qualquer dúvida é só chamar.',
    ];

    public function __construct(private WahaClient $waha) {}

    /** Configuração efetiva por etapa (o que o lojista salvou por cima do padrão). */
    public static function settingsFor(Company $company): array
    {
        $saved = $company->whatsapp_status_notifications ?? [];

        return collect(self::STAGES)->map(fn ($label, $stage) => [
            'label' => $label,
            'enabled' => (bool) ($saved[$stage]['enabled'] ?? true),
            'message' => trim((string) ($saved[$stage]['message'] ?? '')) ?: self::DEFAULT_MESSAGES[$stage],
        ])->all();
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
        dispatch(fn () => $this->send($quoteId, $stage))->afterResponse();
    }

    public function send(int $quoteId, string $stage): void
    {
        try {
            $quote = Quote::with(['customer', 'company', 'items'])->find($quoteId);
            if (! $quote || $quote->production_status !== $stage) {
                return;
            }

            $company = $quote->company;
            $digits = preg_replace('/\D/', '', (string) $quote->customer?->phone);

            if (! $company?->isPro() || ! $company->whatsapp_session_name || $digits === '' || ! $this->waha->isConfigured()) {
                return;
            }

            if (in_array($stage, $quote->notified_stages ?? [], true)) {
                return;
            }

            $config = self::settingsFor($company)[$stage];
            if (! $config['enabled']) {
                return;
            }

            $session = $this->waha->getSession($company->whatsapp_session_name);
            if (($session['status'] ?? null) !== 'WORKING') {
                return;
            }

            $this->waha->sendText($company->whatsapp_session_name, $this->chatId($digits), $this->render($config['message'], $quote, $company));

            $quote->forceFill(['notified_stages' => array_values(array_unique([...($quote->notified_stages ?? []), $stage]))])->save();
        } catch (\Throwable $e) {
            Log::warning("Aviso de etapa não enviado (venda {$quoteId}, {$stage}): ".$e->getMessage());
        }
    }

    /** Telefone só com dígitos → id de conversa do WhatsApp (assume DDI 55 quando vem sem). */
    private function chatId(string $digits): string
    {
        return (strlen($digits) <= 11 ? '55'.$digits : $digits).'@c.us';
    }

    private function render(string $template, Quote $quote, Company $company): string
    {
        $items = $quote->items->map(fn ($i) => (int) $i->quantity.'x '.$i->description)->implode(', ');

        return strtr($template, [
            '{cliente}' => explode(' ', trim((string) $quote->customer?->name))[0] ?: 'cliente',
            '{pedido}' => '#'.$quote->id,
            '{itens}' => mb_strimwidth($items, 0, 200, '…'),
            '{loja}' => $company->name,
        ]);
    }
}
