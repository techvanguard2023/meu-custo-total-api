<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AdminDiscount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    use EnforcesPlanLimits;

    public const ESSENTIAL_PRICE = 14.90;
    public const PRO_PRICE = 29.90;

    /** Plano atual + uso frente aos limites do plano gratuito. */
    public function show(Request $request)
    {
        $company = $request->user()->company;

        return response()->json([
            'plan' => $company->plan,
            'essential_price' => self::ESSENTIAL_PRICE,
            'pro_price' => self::PRO_PRICE,
            'stripe_configured' => $this->stripeConfigured(),
            // Teste grátis do Pro valendo (a faixa do app e a tela de planos mostram quanto falta)
            'trial' => $company->onProTrial() ? [
                'ends_at' => $company->pro_trial_ends_at->toIso8601String(),
                'days_left' => $company->proTrialDaysLeft(),
            ] : null,
            'subscribed' => $company->subscribed('default'),
            // Plano da assinatura em si (no teste o plano em uso é Pro, mas os botões da tela seguem a assinatura)
            'subscription_plan' => Company::planForSubscription($company->subscription('default')),
            // Desconto dado pela plataforma (ficha da loja no admin): a tela de planos mostra
            'discount' => $company->admin_discount && ! AdminDiscount::expired($company->admin_discount) ? [
                'label' => app(AdminDiscount::class)->label($company->admin_discount),
                'status' => $company->admin_discount['status'],
                // prazo pra usar (oferta do fim do teste grátis)
                'expires_at' => $company->admin_discount['expires_at'] ?? null,
            ] : null,
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

    /**
     * Assina um plano pago. Sem assinatura ainda → cria uma sessão do Stripe
     * Checkout; já assinante de um dos planos pagos → troca o preço na mesma
     * assinatura (upgrade/downgrade entre Essencial e Pro, com proration da
     * própria Stripe), sem precisar passar pelo Checkout de novo.
     */
    public function checkout(Request $request)
    {
        abort_unless($this->stripeConfigured(), 422, 'Pagamento ainda não configurado. Contate o suporte.');

        $data = $request->validate([
            'plan' => ['required', Rule::in([Company::PLAN_ESSENTIAL, Company::PLAN_PRO])],
        ]);

        $company = $request->user()->company;
        $priceId = $this->priceFor($data['plan']);
        abort_unless($priceId, 422, 'Este plano ainda não está configurado. Contate o suporte.');

        if ($company->subscribed('default')) {
            abort_if($company->plan === $data['plan'], 422, 'Sua empresa já está neste plano.');

            $company->subscription('default')->swap($priceId);
            // Não espera o webhook: reflete a troca na hora pro usuário já ver o plano certo.
            $company->update(['plan' => $data['plan']]);

            return response()->json(['plan' => $data['plan']]);
        }

        $frontend = rtrim(config('services.frontend_url'), '/');

        $builder = $company->newSubscription('default', $priceId);
        // Assinou no meio do teste grátis: a cobrança só começa quando o teste acabar (não perde os dias que faltam).
        // A Stripe exige pelo menos 48h de teste no checkout; com menos que isso, cobra já.
        if ($company->onProTrial() && $company->pro_trial_ends_at->gt(now()->addHours(49))) {
            $builder->trialUntil($company->pro_trial_ends_at);
        }
        $adminCoupon = ($company->admin_discount['status'] ?? null) === 'pending' && ! AdminDiscount::expired($company->admin_discount)
            ? $company->admin_discount['coupon_id'] : null;
        if ($adminCoupon) {
            // Desconto dado pelo admin (ficha da loja): já entra no checkout. A Stripe não deixa usar junto
            // com o campo de código promocional, então ele some nesse caso.
            $builder->withCoupon($adminCoupon);
        } else {
            // Exibe o campo "Adicionar código promocional" na tela da Stripe. Os cupons e
            // códigos são criados no painel da Stripe; a validação (expirado, limite de usos,
            // só para cliente novo) é toda feita lá — nada disso passa pela nossa API.
            $builder->allowPromotionCodes();
        }

        $checkout = $builder
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

    private function priceFor(string $plan): ?string
    {
        return match ($plan) {
            Company::PLAN_PRO => config('services.stripe.price_pro'),
            Company::PLAN_ESSENTIAL => config('services.stripe.price_essential'),
            default => null,
        };
    }

    private function stripeConfigured(): bool
    {
        return ! empty(config('cashier.secret')) && ! empty(config('services.stripe.price_pro'));
    }
}
