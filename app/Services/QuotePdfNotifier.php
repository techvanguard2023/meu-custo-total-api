<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        dispatch(fn () => app(self::class)->send($quoteId))->afterResponse();
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

            $path = $this->store($quote);
            $url = Storage::disk('public')->url($path);

            $this->waha->sendFile(
                $company->whatsapp_session_name,
                $this->chatId($digits),
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

    public function render(Quote $quote)
    {
        return Pdf::loadView('pdf.quote', ['quote' => $quote])->setPaper('a4');
    }

    private function chatId(string $digits): string
    {
        return (strlen($digits) <= 11 ? '55'.$digits : $digits).'@c.us';
    }

    private function firstName(Quote $quote): string
    {
        return explode(' ', trim((string) $quote->customer?->name))[0] ?: 'cliente';
    }
}
