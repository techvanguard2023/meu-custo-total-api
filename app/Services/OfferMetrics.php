<?php

namespace App\Services;

use App\Models\Company;
use App\Models\OfferPost;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Desempenho das ofertas: cliques no link (o catálogo avisa ao abrir com ?o=CODIGO) e vendas do produto
 * nas 48h depois da oferta — uma estimativa (o pedido não diz de onde veio; pode ter vindo de outro canal).
 */
class OfferMetrics
{
    public const SALES_WINDOW_HOURS = 48;

    /** Registra o clique (sem guardar dado pessoal: hash do aparelho no dia). */
    public function recordClick(string $code, Request $request): void
    {
        $post = OfferPost::where('code', $code)->first();
        if (! $post) {
            return;
        }

        DB::table('offer_clicks')->insert([
            'offer_post_id' => $post->id,
            'company_id' => $post->company_id,
            'visitor_hash' => hash('sha256', implode('|', [$request->ip(), (string) $request->userAgent(), now()->toDateString(), $post->id, config('app.key')])),
            'created_at' => now(),
        ]);
    }

    /**
     * Números de cada oferta: cliques, pessoas diferentes, pedidos e unidades do produto em 48h.
     * Duas consultas pra lista inteira (nada de uma por oferta).
     *
     * @return array<int, array{clicks: int, unique_clicks: int, orders: int, units: int}>
     */
    public function forPosts(Company $company, Collection $posts): array
    {
        if ($posts->isEmpty()) {
            return [];
        }

        $clicks = DB::table('offer_clicks')
            ->whereIn('offer_post_id', $posts->pluck('id'))
            ->selectRaw('offer_post_id, COUNT(*) AS total, COUNT(DISTINCT visitor_hash) AS people')
            ->groupBy('offer_post_id')
            ->get()
            ->keyBy('offer_post_id');

        $from = Carbon::parse($posts->min('created_at'));
        $until = Carbon::parse($posts->max('created_at'))->addHours(self::SALES_WINDOW_HOURS);

        $sales = DB::table('quote_items')
            ->join('quotes', 'quotes.id', '=', 'quote_items.quote_id')
            ->where('quotes.company_id', $company->id)
            ->where('quotes.status', Quote::STATUS_APPROVED)
            ->whereBetween('quotes.created_at', [$from, $until])
            ->where('quote_items.type', 'product')
            ->whereIn('quote_items.product_id', $posts->pluck('product_id')->filter()->unique())
            ->get(['quote_items.product_id', 'quote_items.quantity', 'quotes.id AS quote_id', 'quotes.created_at']);

        // Post de cupom: pedidos que usaram o cupom na janela (não um produto)
        $couponSales = Quote::where('company_id', $company->id)
            ->whereIn('coupon_id', $posts->pluck('coupon_id')->filter()->unique())
            ->whereNotIn('status', [Quote::STATUS_REJECTED, Quote::STATUS_CANCELLED])
            ->whereBetween('created_at', [$from, $until])
            ->get(['id', 'coupon_id', 'created_at']);

        $result = [];
        foreach ($posts as $post) {
            $start = Carbon::parse($post->created_at);
            $end = $start->copy()->addHours(self::SALES_WINDOW_HOURS);
            $window = $post->coupon_id
                ? collect()
                : $sales->filter(fn ($row) => (int) $row->product_id === (int) $post->product_id
                    && Carbon::parse($row->created_at)->betweenIncluded($start, $end));
            $couponOrders = $post->coupon_id
                ? $couponSales->filter(fn ($q) => (int) $q->coupon_id === (int) $post->coupon_id && $q->created_at->betweenIncluded($start, $end))->count()
                : 0;

            $result[$post->id] = [
                'clicks' => (int) ($clicks[$post->id]->total ?? 0),
                'unique_clicks' => (int) ($clicks[$post->id]->people ?? 0),
                'orders' => $post->coupon_id ? $couponOrders : $window->pluck('quote_id')->unique()->count(),
                'units' => (int) $window->sum('quantity'),
            ];
        }

        return $result;
    }

    /** Resumo dos últimos N dias das ofertas da loja (enviadas, cliques, pessoas, pedidos em 48h) — sem os achadinhos. */
    public function summary(Company $company, int $days = 30): array
    {
        $posts = OfferPost::where('company_id', $company->id)->whereNull('affiliate_offer_id')->where('origin', 'not like', 'affiliate%')
            ->where('created_at', '>=', now()->subDays($days))->get();
        $metrics = collect($this->forPosts($company, $posts));

        return [
            'days' => $days,
            'posts' => $posts->count(),
            'clicks' => $metrics->sum('clicks'),
            'unique_clicks' => $metrics->sum('unique_clicks'),
            'orders' => $metrics->sum('orders'),
        ];
    }
}
