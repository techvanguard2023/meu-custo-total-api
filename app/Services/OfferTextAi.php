<?php

namespace App\Services;

use App\Models\AffiliateOffer;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Texto da oferta por IA: o fluxo "Texto de oferta (IA)" no n8n recebe o produto e devolve um texto curto
 * e chamativo. Só o texto — preço e link continuam montados pelo sistema (a IA nunca escreve preço).
 * O lojista revisa antes de salvar.
 */
class OfferTextAi
{
    public function __construct(private OfferPublisher $publisher) {}

    public function isConfigured(): bool
    {
        return (bool) config('services.n8n.offer_text_webhook');
    }

    public function generate(Company $company, Product $product, ?string $instructions = null): string
    {
        [$regular, $promo, $from] = $this->publisher->prices($company, $product);

        return $this->request($company, [
            'store' => $company->name,
            'segment' => $company->segment,
            'product' => [
                'name' => $product->name,
                'description' => trim(strip_tags((string) $product->description)),
                'category' => $product->category?->name,
                'price' => $promo ?? $regular,
                'original_price' => $promo !== null ? $regular : null,
                'from_price' => $from,
                'made_to_order' => (bool) $product->made_to_order,
            ],
            'instructions' => $instructions,
        ]);
    }

    /**
     * Achadinho: mesmo fluxo do n8n, com a loja de origem no lugar da loja e um aviso no começo das instruções
     * (o fluxo corta em 300 caracteres) pra IA não falar como se o produto fosse da pessoa.
     */
    public function generateAffiliate(Company $company, AffiliateOffer $offer, ?string $instructions = null): string
    {
        $note = 'É um achadinho de outra loja, divulgado com link de afiliado: não diga que o produto é nosso nem fale de entrega ou prazo.';

        return $this->request($company, [
            'store' => $offer->marketplace_label,
            'segment' => null,
            'product' => [
                'name' => $offer->title,
                'description' => trim(strip_tags((string) $offer->description)),
                'category' => null,
                'price' => $offer->price !== null ? (float) $offer->price : null,
                'original_price' => $offer->original_price !== null && $offer->price !== null && (float) $offer->original_price > (float) $offer->price
                    ? (float) $offer->original_price : null,
                'from_price' => false,
                'made_to_order' => false,
            ],
            'instructions' => trim($note.' '.(string) $instructions),
        ]);
    }

    private function request(Company $company, array $payload): string
    {
        if (! $this->isConfigured()) {
            throw ValidationException::withMessages(['ai' => 'A geração de texto por IA ainda não foi configurada.']);
        }

        try {
            $response = Http::timeout(45)
                ->withHeaders(['X-Webhook-Secret' => (string) config('services.n8n.webhook_secret')])
                ->post(config('services.n8n.offer_text_webhook'), $payload)
                ->throw();
        } catch (Throwable $e) {
            Log::warning("Ofertas: IA não respondeu (empresa {$company->id}): {$e->getMessage()}");
            throw ValidationException::withMessages(['ai' => 'A IA não respondeu agora. Tente de novo em instantes.']);
        }

        $text = trim((string) ($response->json('text') ?? $response->json('output') ?? ''));
        if ($text === '') {
            throw ValidationException::withMessages(['ai' => 'A IA devolveu um texto vazio. Tente de novo.']);
        }

        return mb_substr($text, 0, 700);
    }
}
