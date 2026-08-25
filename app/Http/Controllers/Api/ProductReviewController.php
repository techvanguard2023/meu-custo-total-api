<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Avaliações recebidas, do lado do lojista: moderação dos comentários e geração
 * do link que ele envia ao cliente. Recurso Pro, como o catálogo — é onde as
 * avaliações aparecem.
 */
class ProductReviewController extends Controller
{
    use EnforcesPlanLimits;

    public function index(Request $request)
    {
        $this->requirePro($request, 'Avaliações de produto');

        return $request->user()->company->productReviews()
            ->with(['product:id,name', 'quote:id,name'])
            ->latest()
            ->get();
    }

    /**
     * Gera (ou reaproveita) o link de avaliação de uma venda. O token não é
     * regerado a cada chamada: se o lojista já mandou o link e clica de novo,
     * o que o cliente tem em mãos precisa continuar valendo.
     */
    public function link(Request $request, Quote $quote)
    {
        $this->requirePro($request, 'Avaliações de produto');
        abort_unless($quote->company_id === $request->user()->company_id, 403);
        abort_unless($quote->status === Quote::STATUS_APPROVED, 422, 'Só vendas aprovadas podem ser avaliadas.');

        // Sem produto de catálogo vinculado (peça sob encomenda), a avaliação
        // vira sobre a loja como um todo — ainda assim tem o que avaliar.
        if (! $quote->review_token) {
            $quote->update(['review_token' => Str::random(32)]);
        }

        return response()->json([
            'review_token' => $quote->review_token,
            'url' => rtrim(config('services.frontend_url'), '/').'/avaliar/'.$quote->review_token,
        ]);
    }

    /** Libera ou barra o comentário. A nota não passa por aqui: ela já está na média. */
    public function moderate(Request $request, ProductReview $productReview)
    {
        $this->requirePro($request, 'Avaliações de produto');
        $this->authorizeCompany($request, $productReview);

        $data = $request->validate([
            'comment_status' => [
                'required',
                Rule::in([ProductReview::COMMENT_APPROVED, ProductReview::COMMENT_REJECTED]),
            ],
        ]);

        $productReview->update(['comment_status' => $data['comment_status']]);

        return response()->json($productReview->fresh()->load(['product:id,name', 'quote:id,name']));
    }

    /**
     * Remove a avaliação inteira — nota e comentário. A nota sai da média do
     * produto no catálogo.
     */
    public function destroy(Request $request, ProductReview $productReview)
    {
        $this->authorizeCompany($request, $productReview);

        $productReview->delete();

        return response()->json(null, 204);
    }

    private function authorizeCompany(Request $request, ProductReview $review): void
    {
        abort_unless($review->company_id === $request->user()->company_id, 403);
    }
}
