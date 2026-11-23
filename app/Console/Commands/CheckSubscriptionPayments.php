<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;

/**
 * Assinaturas com cobrança recusada (past_due): confere na Stripe quantas tentativas a fatura em aberto já teve
 * (caso o webhook invoice.payment_failed não chegue) e reavalia o plano — suspende na 3ª recusa ou depois de 21
 * dias, e devolve o plano quando a fatura foi paga.
 */
class CheckSubscriptionPayments extends Command
{
    protected $signature = 'billing:check-payments';

    protected $description = 'Conta as tentativas de cobrança das assinaturas em atraso e suspende quem esgotou as tentativas';

    public function handle(): int
    {
        $companies = Company::where(fn ($q) => $q->whereNotNull('payment_failed_at')
            ->orWhereHas('subscriptions', fn ($s) => $s->where('type', 'default')->where('stripe_status', 'past_due')))
            ->get();

        foreach ($companies as $company) {
            $subscription = $company->subscription('default');
            $before = $company->plan;

            if (! $subscription || $subscription->stripe_status !== 'past_due') {
                // Voltou a ativa (ou foi cancelada): as recusas não contam mais
                $company->clearPaymentFailure();
            } else {
                $company->recordPaymentFailure($this->attempts($subscription) ?? 1);
            }

            $company->refresh();
            if ($company->plan !== $before) {
                $this->warn("Empresa #{$company->id} ({$company->name}): {$before} → {$company->plan} ({$company->payment_failed_attempts} recusa(s))");
            }
        }

        return self::SUCCESS;
    }

    /** Tentativas da última fatura na Stripe (null se não deu para consultar). */
    private function attempts(Subscription $subscription): ?int
    {
        if (! config('cashier.secret')) {
            return null;
        }
        try {
            $invoice = $subscription->latestInvoice();

            return $invoice ? (int) ($invoice->asStripeInvoice()->attempt_count ?? 0) : null;
        } catch (\Throwable $e) {
            Log::warning("Stripe: tentativas da assinatura {$subscription->stripe_id} não consultadas: ".$e->getMessage());

            return null;
        }
    }
}
