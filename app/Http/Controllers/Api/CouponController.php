<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Services\Coupons;
use App\Services\CustomerPortal;
use App\Services\QuoteCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Cupons de desconto da loja (Pro). Cadastro só do dono; a conferência pública atende o carrinho do catálogo. */
class CouponController extends Controller
{
    use EnforcesPlanLimits;

    public function index(Request $request)
    {
        $this->requirePro($request, 'Cupons de desconto');

        $company = $request->user()->company;

        return $company->coupons()
            ->withCount(['usedQuotes as uses_count'])
            ->withSum(['usedQuotes as discount_total'], 'coupon_amount')
            ->withSum(['usedQuotes as revenue_total'], 'final_price')
            ->with('offerSeries:id,coupon_id,name')
            ->latest()
            ->get()
            ->map(fn (Coupon $c) => $this->payload($c, $company));
    }

    /** Pedidos que usaram o cupom (os mais recentes), pra conferir de onde veio cada venda. */
    public function orders(Request $request, Coupon $coupon)
    {
        $this->requirePro($request, 'Cupons de desconto');
        abort_unless($coupon->company_id === $request->user()->company_id, 404);

        return $coupon->quotes()->with('customer:id,name')->latest('id')->limit(100)->get()->map(fn (Quote $q) => [
            'id' => $q->id,
            'date' => ($q->approved_at ?? $q->created_at)?->toIso8601String(),
            'customer' => $q->customer?->name,
            'status' => $q->status,
            'counted' => ! in_array($q->status, [Quote::STATUS_REJECTED, Quote::STATUS_CANCELLED], true),
            'final_price' => (float) $q->final_price,
            'coupon_amount' => (float) $q->coupon_amount,
            'payment_status' => $q->payment_status,
        ]);
    }

    /** Link do catálogo que já deixa o cupom no carrinho, e em quais séries de ofertas ele está. */
    private function payload(Coupon $coupon, Company $company): array
    {
        return array_merge($coupon->toArray(), [
            'share_url' => $company->catalogUrl().'?cupom='.$coupon->code,
            'offer_series' => $coupon->offerSeries->pluck('name')->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->requirePro($request, 'Cupons de desconto');

        $coupon = $request->user()->company->coupons()->create($this->validated($request));

        return response()->json($this->loadNumbers($coupon, $request->user()->company), 201);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $this->requirePro($request, 'Cupons de desconto');
        abort_unless($coupon->company_id === $request->user()->company_id, 404);

        $coupon->update($this->validated($request, $coupon));

        return response()->json($this->loadNumbers($coupon, $request->user()->company));
    }

    /** Pedidos que já usaram o cupom guardam o código e o desconto — só deixam de apontar pro cadastro. */
    public function destroy(Request $request, Coupon $coupon)
    {
        abort_unless($coupon->company_id === $request->user()->company_id, 404);

        $coupon->delete();

        return response()->noContent();
    }

    /**
     * Carrinho do catálogo: o cupom vale para estes itens? Devolve o desconto pra mostrar no total. Com o
     * cliente logado em "Meus pedidos", já confere as regras por cliente; sem login, elas ficam pra quando
     * o pedido for criado. Sempre 200 (com `valid` e `message`), pra o carrinho só exibir o motivo.
     */
    public function publicCheck(Request $request, string $token, Coupons $coupons, QuoteCalculatorService $calculator, CustomerPortal $portal)
    {
        $company = Company::active()->where(fn ($q) => $q->where('slug', $token)->orWhere('catalog_token', $token))->first();
        abort_unless($company && $company->hasCatalogActive(), 404, 'Catálogo não encontrado.');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variation_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $invalid = fn (string $message) => response()->json(['valid' => false, 'message' => $message]);

        $coupon = $company->isPro() ? $coupons->find($company, $data['code']) : null;
        if (! $coupon) {
            return $invalid('Cupom não encontrado.');
        }

        $phoneKey = $portal->phoneKeyForToken($company, $request->header('X-Customer-Token'));
        $customer = $phoneKey ? Customer::whereIn('id', $portal->customerIds($company, $phoneKey))->oldest('id')->first() : null;

        $reason = $coupons->unavailableReason($coupon, $customer, customerKnown: false);
        if ($reason) {
            return $invalid($reason);
        }

        // Mesmos produtos e preços que o pedido vai usar (só os que estão no catálogo)
        $products = Product::where('company_id', $company->id)->where('active', true)->where('show_in_catalog', true)
            ->whereIn('id', collect($data['items'])->pluck('product_id'))
            ->with(['variations', 'category', 'company'])
            ->get()->keyBy('id');

        $lines = collect($data['items'])->map(function (array $item) use ($products) {
            $product = $products->get($item['product_id']);
            $variation = $product && ! empty($item['product_variation_id']) ? $product->variations->firstWhere('id', (int) $item['product_variation_id']) : null;
            if (! $product || ($product->has_variations && ! $variation)) {
                return null;
            }

            return ['product' => $product, 'variation' => $variation, 'quantity' => (int) $item['quantity']];
        })->filter()->values()->all();

        if ($lines === []) {
            return $invalid('O cupom não vale para os itens deste pedido.');
        }

        $breakdown = $calculator->calculate(['quantity' => 1, 'coupon' => $coupon], [], null, $company->setting, $lines);

        if ($breakdown['coupon_amount'] <= 0) {
            return $invalid($breakdown['coupon_note'] ?? 'O cupom não vale para os itens deste pedido.');
        }

        return response()->json([
            'valid' => true,
            'code' => $coupon->code,
            'label' => $coupon->label(),
            'amount' => $breakdown['coupon_amount'],
            'message' => null,
        ]);
    }

    private function loadNumbers(Coupon $coupon, Company $company): array
    {
        $coupon->loadCount(['usedQuotes as uses_count'])
            ->loadSum(['usedQuotes as discount_total'], 'coupon_amount')
            ->loadSum(['usedQuotes as revenue_total'], 'final_price')
            ->load('offerSeries:id,coupon_id,name');

        return $this->payload($coupon, $company);
    }

    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $companyId = $request->user()->company_id;
        $request->merge(['code' => Coupon::normalizeCode($request->input('code'))]);

        $data = $request->validate([
            'code' => [
                'required', 'string', 'min:3', 'max:30', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('coupons', 'code')->where('company_id', $companyId)->ignore($coupon?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            'value' => ['required', 'numeric', 'gt:0', Rule::when($request->input('type') === Coupon::TYPE_PERCENT, ['max:100'])],
            'max_discount' => ['nullable', 'numeric', 'gt:0'],
            'min_order' => ['nullable', 'numeric', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'first_purchase_only' => ['boolean'],
            'allow_promotions' => ['boolean'],
            'category_ids' => ['nullable', 'array'],
            // Categorias padrão (sem dono) valem pra todas as lojas
            'category_ids.*' => ['integer', Rule::exists('product_categories', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'active' => ['boolean'],
        ], [
            'code.regex' => 'Use só letras, números, "-" e "_" no código (sem espaços).',
            'code.unique' => 'Já existe um cupom com esse código.',
            'value.max' => 'O desconto em % vai até 100.',
            'ends_on.after_or_equal' => 'O fim da validade precisa ser depois do início.',
        ]);

        // Teto só faz sentido em %; vazio vira "sem restrição"
        if ($data['type'] === Coupon::TYPE_FIXED) {
            $data['max_discount'] = null;
        }
        $data['category_ids'] = array_values(array_unique(array_map('intval', $data['category_ids'] ?? []))) ?: null;
        $data['product_ids'] = array_values(array_unique(array_map('intval', $data['product_ids'] ?? []))) ?: null;

        return $data;
    }
}
