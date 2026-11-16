<?php

namespace App\Services;

use App\Support\Background;
use App\Models\Company;
use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gera o PDF de um orçamento e manda pelo WhatsApp do cliente — usado depois
 * que o vendedor precifica uma solicitação vinda do bot (QuoteRequest). Nunca
 * atrapalha quem está salvando o orçamento: roda depois da resposta e
 * qualquer falha só vai pro log (o vendedor sempre pode baixar o PDF na tela
 * do orçamento e mandar manualmente).
 */
class QuotePdfNotifier
{
    public function __construct(private WahaClient $waha) {}

    /** Chamar depois de vincular o orçamento precificado a uma solicitação do WhatsApp. */
    public function notify(int $quoteId): void
    {
        Background::run(static fn () => app(self::class)->send($quoteId));
    }

    public function send(int $quoteId): void
    {
        try {
            $quote = Quote::with(['customer', 'company', 'items'])->find($quoteId);
            if (! $quote) {
                return;
            }

            $company = $quote->company;
            $digits = preg_replace('/\D/', '', (string) $quote->customer?->phone);

            if (! $company?->isPro() || ! $company->whatsapp_session_name || $digits === '' || ! $this->waha->isConfigured()) {
                return;
            }

            $session = $this->waha->getSession($company->whatsapp_session_name);
            if (($session['status'] ?? null) !== 'WORKING') {
                return;
            }

            $chatId = $this->waha->chatIdFor($company->whatsapp_session_name, $digits);
            if ($chatId === null) {
                return;
            }

            $path = $this->store($quote);
            $url = Storage::disk('public')->url($path);

            $this->waha->sendFile(
                $company->whatsapp_session_name,
                $chatId,
                $url,
                "orcamento-{$quote->id}.pdf",
                "Olá, {$this->firstName($quote)}! Segue o orçamento *#{$quote->id}* da {$company->name}. Qualquer dúvida, estamos por aqui."
            );
        } catch (\Throwable $e) {
            Log::warning("PDF de orçamento não enviado (orçamento {$quoteId}): ".$e->getMessage());
        }
    }

    /** Gera (ou regera) o PDF do orçamento no disco público e devolve o caminho salvo. */
    public function store(Quote $quote): string
    {
        $pdf = $this->render($quote);
        $path = "quotes/pdf/{$quote->id}.pdf";
        Storage::disk('public')->put($path, $pdf->output());

        return $path;
    }

    /**
     * Botão "Enviar no WhatsApp" do orçamento: o PDF (versão do cliente, sem custos nem lucro) vai pelo WhatsApp
     * conectado da loja. Não deu (sem telefone, WhatsApp desconectado, plano sem bot)? O motivo volta e a tela
     * oferece abrir o WhatsApp do lojista com o resumo pronto (summaryText).
     *
     * @return array{sent: bool, reason: string|null}  reason: no_phone | not_connected | not_on_whatsapp | failed
     */
    public function sendNow(Quote $quote): array
    {
        $quote->loadMissing(['customer', 'company', 'items']);
        $company = $quote->company;
        $digits = preg_replace('/\D/', '', (string) $quote->customer?->phone);

        if ($digits === '') {
            return ['sent' => false, 'reason' => 'no_phone'];
        }
        if (! $company->isPro() || ! $company->whatsapp_session_name || ! $this->waha->isConfigured()) {
            return ['sent' => false, 'reason' => 'not_connected'];
        }

        try {
            $session = $this->waha->getSession($company->whatsapp_session_name);
            if (($session['status'] ?? null) !== 'WORKING') {
                return ['sent' => false, 'reason' => 'not_connected'];
            }
            $chatId = $this->waha->chatIdFor($company->whatsapp_session_name, $digits);
            if ($chatId === null) {
                return ['sent' => false, 'reason' => 'not_on_whatsapp'];
            }

            // Nome aleatório: o link do PDF não dá para adivinhar trocando o número do orçamento
            $path = "quotes/pdf/{$quote->id}-".Str::random(24).'.pdf';
            Storage::disk('public')->put($path, $this->render($quote)->output());

            $this->waha->sendFile(
                $company->whatsapp_session_name,
                $chatId,
                Storage::disk('public')->url($path),
                "orcamento-{$quote->id}.pdf",
                "Olá, {$this->firstName($quote)}! Segue o orçamento *#{$quote->id}* da {$company->name}. Qualquer dúvida, estamos por aqui."
            );

            return ['sent' => true, 'reason' => null];
        } catch (\Throwable $e) {
            Log::warning("Orçamento não enviado pelo WhatsApp (orçamento {$quote->id}): ".$e->getMessage());

            return ['sent' => false, 'reason' => 'failed'];
        }
    }

    /** Resumo do orçamento para o WhatsApp do lojista (wa.me): itens, preços de venda e total — nunca custos. */
    public function summaryText(Quote $quote): string
    {
        $quote->loadMissing(['customer', 'company', 'items']);
        $brl = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');

        $lines = ["Olá, {$this->firstName($quote)}! Segue o orçamento *#{$quote->id}* da {$quote->company->name}:", ''];
        foreach ($quote->soldLines() as $line) {
            $lines[] = "• {$line['quantity']}x {$line['description']} — {$brl((float) $line['amount'])}";
        }
        if ((float) $quote->coupon_amount > 0) {
            $lines[] = "• Cupom {$quote->coupon_code}: - {$brl((float) $quote->coupon_amount)}";
        }
        $lines[] = '';
        $lines[] = "*Total: {$brl((float) $quote->final_price)}*";
        if ($quote->delivery_date || $quote->delivery_method) {
            $lines[] = 'Entrega: '.\App\Support\Delivery::label($quote);
        } elseif ($quote->delivery_days) {
            $lines[] = "Prazo: {$quote->delivery_days} ".($quote->delivery_days == 1 ? 'dia' : 'dias').' após a confirmação';
        }
        $lines[] = '';
        $lines[] = 'Qualquer dúvida, estamos por aqui.';

        return implode("\n", $lines);
    }

    public function render(Quote $quote)
    {
        return Pdf::loadView('pdf.quote', ['quote' => $quote])->setPaper('a4');
    }

    private function firstName(Quote $quote): string
    {
        return explode(' ', trim((string) $quote->customer?->name))[0] ?: 'cliente';
    }
}
