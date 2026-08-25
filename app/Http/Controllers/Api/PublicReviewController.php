<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Página de avaliação aberta pelo cliente final — sem autenticação, acessada só
 * pelo link que o lojista envia depois de entregar. Nunca expõe custo, margem
 * ou qualquer dado interno da venda: só o necessário pra pessoa reconhecer o
 * que comprou e dar a nota.
 */
class PublicReviewController extends Controller
{
    /** Dados da venda pra montar a tela de avaliação. */
    public function show(Request $request, string $token)
    {
        $quote = $this->resolveQuote($token);

        return response()->json($this->payload($quote));
    }

    /**
     * Recebe as notas. É upsert por produto (ou pela loja, quando a venda não
     * tem produto de catálogo): reabrir o link e enviar de novo corrige a
     * avaliação anterior em vez de duplicar — errar o clique numa estrela não
     * pode ser irreversível pro cliente.
     */
    public function store(Request $request, string $token)
    {
        $quote = $this->resolveQuote($token);
        $soldProductIds = $this->soldProductIds($quote);

        // Venda sob encomenda, sem produto de catálogo vinculado: não tem o que
        // avaliar por item, então a avaliação é sobre a loja como um todo.
        if (empty($soldProductIds)) {
            $data = $request->validate([
                'store_review' => ['required', 'array'],
                'store_review.rating' => ['required', 'integer', 'min:1', 'max:5'],
                'store_review.comment' => ['nullable', 'string', 'max:1000'],
            ], [
                'store_review.required' => 'Escolha uma nota antes de enviar.',
                'store_review.rating.min' => 'A nota deve ser de 1 a 5 estrelas.',
                'store_review.rating.max' => 'A nota deve ser de 1 a 5 estrelas.',
            ]);

            $this->upsertReview($quote, null, $data['store_review']);

            return response()->json([
                'message' => 'Obrigado pela avaliação!',
                ...$this->payload($quote->fresh()),
            ]);
        }

        $data = $request->validate([
            'reviews' => ['required', 'array', 'min:1'],
            'reviews.*.product_id' => ['required', 'integer'],
            'reviews.*.rating' => ['required', 'integer', 'min:1', 'max:5'],
            'reviews.*.comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'reviews.required' => 'Escolha ao menos uma nota antes de enviar.',
            'reviews.*.rating.min' => 'A nota deve ser de 1 a 5 estrelas.',
            'reviews.*.rating.max' => 'A nota deve ser de 1 a 5 estrelas.',
        ]);

        // Só produtos que realmente saíram nesta venda — sem isso daria pra
        // avaliar qualquer produto do catálogo tendo um link válido em mãos.
        DB::transaction(function () use ($quote, $data, $soldProductIds) {
            foreach ($data['reviews'] as $row) {
                $productId = (int) $row['product_id'];
                if (! in_array($productId, $soldProductIds, true)) {
                    continue;
                }

                $this->upsertReview($quote, $productId, $row);
            }
        });

        return response()->json([
            'message' => 'Obrigado pela avaliação!',
            ...$this->payload($quote->fresh()),
        ]);
    }

    /** Cria ou atualiza uma avaliação (de produto, ou da loja quando $productId é nulo). */
    private function upsertReview(Quote $quote, ?int $productId, array $row): void
    {
        $comment = isset($row['comment']) ? trim((string) $row['comment']) : '';
        $existing = ProductReview::where('quote_id', $quote->id)
            ->where('product_id', $productId)
            ->first();

        $attributes = [
            'company_id' => $quote->company_id,
            'quote_id' => $quote->id,
            'product_id' => $productId,
            'rating' => (int) $row['rating'],
            'reviewer_name' => $quote->customer?->name,
        ];

        // Comentário reescrito volta pra moderação: o lojista aprovou o texto
        // antigo, não este.
        if ($comment !== '' && $comment !== ($existing->comment ?? null)) {
            $attributes['comment'] = $comment;
            $attributes['comment_status'] = ProductReview::COMMENT_PENDING;
        } elseif ($comment === '') {
            $attributes['comment'] = null;
            $attributes['comment_status'] = ProductReview::COMMENT_PENDING;
        }

        if ($existing) {
            $existing->update($attributes);
        } else {
            ProductReview::create($attributes);
        }
    }

    /** IDs dos produtos de catálogo vendidos nesta venda. */
    private function soldProductIds(Quote $quote): array
    {
        return $quote->items()
            ->where('type', 'product')
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();
    }

    private function payload(Quote $quote): array
    {
        $base = [
            'company_name' => $quote->company->name,
            'logo_url' => $quote->company->logo_url,
            'customer_name' => $quote->customer?->name,
        ];

        $soldProductIds = $this->soldProductIds($quote);

        if (empty($soldProductIds)) {
            $storeReview = ProductReview::where('quote_id', $quote->id)->whereNull('product_id')->first();

            return [
                ...$base,
                'products' => [],
                'store_review' => [
                    'rating' => $storeReview?->rating,
                    'comment' => $storeReview?->comment,
                ],
            ];
        }

        $existing = $quote->reviews()->whereNotNull('product_id')->get()->keyBy('product_id');

        $products = $quote->items()
            ->where('type', 'product')
            ->whereNotNull('product_id')
            ->with('product')
            ->get()
            // Um mesmo produto pode aparecer em mais de uma linha da venda; a
            // avaliação é por produto, não por linha.
            ->unique('product_id')
            ->filter(fn ($item) => $item->product !== null)
            ->map(function ($item) use ($existing) {
                $review = $existing->get($item->product_id);

                return [
                    'product_id' => $item->product_id,
                    'name' => $item->product->name,
                    'image_url' => $item->product->image_url,
                    'rating' => $review?->rating,
                    'comment' => $review?->comment,
                ];
            })
            ->values();

        return [
            ...$base,
            'products' => $products,
            'store_review' => null,
        ];
    }

    /**
     * Link só vale pra venda aprovada e não cancelada. Um 404 genérico cobre
     * token inválido, venda cancelada e link antigo — de fora não dá pra
     * distinguir os casos, nem deveria.
     */
    private function resolveQuote(string $token): Quote
    {
        $quote = Quote::where('review_token', $token)
            ->where('status', Quote::STATUS_APPROVED)
            ->with(['company', 'customer'])
            ->first();

        abort_unless($quote, 404, 'Link de avaliação não encontrado.');

        return $quote;
    }
}
