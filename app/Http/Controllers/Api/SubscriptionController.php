<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Cashier\Subscription;

class SubscriptionController extends Controller
{
    /** Dados da assinatura + histórico de faturas. */
    public function show(Request $request)
    {
        $company = $request->user()->company;
        $subscription = $company->subscription('default');

        return response()->json([
            'plan' => $company->plan,
            'subscription' => $subscription ? $this->subscriptionData($subscription) : null,
            'card' => $company->pm_last_four
                ? ['brand' => $company->pm_type, 'last_four' => $company->pm_last_four]
                : null,
            'invoices' => $this->invoices($company),
        ]);
    }

    /** Cancela ao fim do período já pago (o webhook rebaixa o plano quando expirar). */
    public function cancel(Request $request)
    {
        $subscription = $request->user()->company->subscription('default');

        abort_unless($subscription && $subscription->valid(), 422, 'Nenhuma assinatura ativa para cancelar.');
        abort_if($subscription->onGracePeriod(), 422, 'A assinatura já está com cancelamento agendado.');

        $subscription->cancel();

        $endsAt = $subscription->ends_at?->format('d/m/Y');

        return response()->json([
            'message' => $endsAt
                ? "Assinatura cancelada. Você mantém o acesso Pro até {$endsAt}."
                : 'Assinatura cancelada.',
            'subscription' => $this->subscriptionData($subscription->fresh()),
        ]);
    }

    /** Reativa uma assinatura com cancelamento agendado (ainda no período pago). */
    public function resume(Request $request)
    {
        $subscription = $request->user()->company->subscription('default');

        abort_unless(
            $subscription && $subscription->onGracePeriod(),
            422,
            'Só é possível reativar uma assinatura com cancelamento agendado.'
        );

        $subscription->resume();

        return response()->json([
            'message' => 'Assinatura reativada com sucesso!',
            'subscription' => $this->subscriptionData($subscription->fresh()),
        ]);
    }

    private function subscriptionData(Subscription $subscription): array
    {
        $renewsAt = null;

        try {
            $stripeSubscription = $subscription->asStripeSubscription();
            // API novas movem current_period_end para os itens da assinatura
            $periodEnd = $stripeSubscription->items->data[0]->current_period_end
                ?? $stripeSubscription->current_period_end
                ?? null;
            $renewsAt = $periodEnd ? date('c', $periodEnd) : null;
        } catch (\Throwable) {
            // Stripe indisponível: segue sem a data de renovação
        }

        return [
            'status' => $subscription->stripe_status,
            'active' => $subscription->valid(),
            'on_grace_period' => $subscription->onGracePeriod(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'renews_at' => $renewsAt,
            'created_at' => $subscription->created_at?->toIso8601String(),
        ];
    }

    private function invoices($company): array
    {
        if (! $company->hasStripeId()) {
            return [];
        }

        try {
            return $company->invoices()
                ->take(24)
                ->map(fn ($invoice) => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'date' => $invoice->date()->toIso8601String(),
                    'total' => $invoice->total(),
                    'status' => $invoice->status,
                    'url' => $invoice->asStripeInvoice()->hosted_invoice_url,
                    'pdf' => $invoice->asStripeInvoice()->invoice_pdf,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
