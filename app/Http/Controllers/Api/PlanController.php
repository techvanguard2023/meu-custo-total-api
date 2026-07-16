<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    use EnforcesPlanLimits;

    public const PRO_PRICE = 29.90;

    /** Plano atual + uso frente aos limites do plano gratuito. */
    public function show(Request $request)
    {
        $company = $request->user()->company;

        return response()->json([
            'plan' => $company->plan,
            'pro_price' => self::PRO_PRICE,
            'stripe_configured' => $this->stripeConfigured(),
            'limits' => self::FREE_LIMITS,
            'usage' => [
                'printers' => $company->printers()->count(),
                'products' => $company->products()->count(),
                'customers' => $company->customers()->count(),
                'quotes_per_month' => $company->quotes()
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count(),
            ],
        ]);
    }

    /** Cria uma sessão do Stripe Checkout para assinar o plano Pro. */
    public function checkout(Request $request)
    {
        abort_unless($this->stripeConfigured(), 422, 'Pagamento ainda não configurado. Contate o suporte.');

        $company = $request->user()->company;

        abort_if($company->isPro(), 422, 'Sua empresa já está no plano Pro.');

        $frontend = rtrim(config('services.frontend_url'), '/');

        $checkout = $company
            ->newSubscription('default', config('services.stripe.price_pro'))
            ->checkout([
                'success_url' => $frontend.'/plans?checkout=success',
                'cancel_url' => $frontend.'/plans?checkout=cancelled',
            ]);

        return response()->json(['url' => $checkout->url]);
    }

    /** Portal de cobrança da Stripe (trocar cartão, cancelar assinatura). */
    public function portal(Request $request)
    {
        abort_unless($this->stripeConfigured(), 422, 'Pagamento ainda não configurado. Contate o suporte.');

        $company = $request->user()->company;

        abort_unless($company->hasStripeId(), 422, 'Nenhuma assinatura encontrada para esta empresa.');

        $frontend = rtrim(config('services.frontend_url'), '/');

        return response()->json(['url' => $company->billingPortalUrl($frontend.'/plans')]);
    }

    private function stripeConfigured(): bool
    {
        return ! empty(config('cashier.secret')) && ! empty(config('services.stripe.price_pro'));
    }
}
