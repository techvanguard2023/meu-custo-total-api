<?php

namespace App\Services;

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
        if (! $this->isConfigured()) {
            throw ValidationException::withMessages(['ai' => 'A geração de texto por IA ainda não foi configurada.']);
        }

        [$regular, $promo, $from] = $this->publisher->prices($company, $product);

        try {
            $response = Http::timeout(45)
                ->withHeaders(['X-Webhook-Secret' => (string) config('services.n8n.webhook_secret')])
                ->post(config('services.n8n.offer_text_webhook'), [
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
                ])
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
