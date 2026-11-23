<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

/** Cobrança da assinatura recusada: a loja segue no plano durante as retentativas e só é suspensa na 3ª recusa. */
class SubscriptionPaymentRetryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.price_pro' => 'price_pro', 'cashier.secret' => null]);
        $this->company = Company::create(['name' => 'Alma Forma', 'slug' => 'alma-forma', 'plan' => Company::PLAN_PRO]);
        $this->company->forceFill(['stripe_id' => 'cus_1'])->save();
        $this->company->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active', 'stripe_price' => 'price_pro', 'quantity' => 1]);
    }

    private function invoice(string $type, int $attempt = 1, string $reason = 'subscription_cycle'): void
    {
        WebhookReceived::dispatch(['type' => $type, 'data' => ['object' => [
            'id' => 'in_1', 'customer' => 'cus_1', 'billing_reason' => $reason, 'attempt_count' => $attempt,
        ]]]);
    }

    /** Como o Cashier: atualiza a assinatura local e depois avisa */
    private function subscriptionStatus(string $status): void
    {
        $this->company->subscriptions()->update(['stripe_status' => $status]);
        WebhookHandled::dispatch(['type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_1', 'customer' => 'cus_1', 'status' => $status]]]);
    }

    private function plan(): string
    {
        return $this->company->fresh()->plan;
    }

    public function test_store_keeps_the_plan_until_the_third_refused_charge(): void
    {
        $this->invoice('invoice.payment_failed', 1);
        $this->subscriptionStatus('past_due');
        $this->assertSame(Company::PLAN_PRO, $this->plan());
        $this->assertSame(1, $this->company->fresh()->payment_failed_attempts);

        $this->invoice('invoice.payment_failed', 2);
        $this->assertSame(Company::PLAN_PRO, $this->plan());

        $this->invoice('invoice.payment_failed', 3);
        $this->assertSame(Company::PLAN_FREE, $this->plan());

        // Pagou numa retentativa ou trocou o cartão: volta na hora
        $this->invoice('invoice.paid');
        $this->assertSame(Company::PLAN_PRO, $this->plan());
        $this->subscriptionStatus('active');
        $company = $this->company->fresh();
        $this->assertSame(Company::PLAN_PRO, $company->plan);
        $this->assertSame(0, $company->payment_failed_attempts);
        $this->assertNull($company->payment_failed_at);
    }

    public function test_past_due_without_the_invoice_event_is_suspended_after_21_days(): void
    {
        $this->subscriptionStatus('past_due');
        $this->assertSame(Company::PLAN_PRO, $this->plan());
        $this->assertSame(1, $this->company->fresh()->payment_failed_attempts);

        $this->travel(20)->days();
        $this->artisan('billing:check-payments')->assertSuccessful();
        $this->assertSame(Company::PLAN_PRO, $this->plan());

        $this->travel(2)->days();
        $this->artisan('billing:check-payments')->assertSuccessful();
        $this->assertSame(Company::PLAN_FREE, $this->plan());
    }

    public function test_canceled_subscription_still_drops_the_plan_right_away(): void
    {
        // Como o Cashier no customer.subscription.deleted: encerrada agora
        $this->company->subscriptions()->update(['ends_at' => now()->subMinute()]);
        $this->subscriptionStatus('canceled');
        $this->assertSame(Company::PLAN_FREE, $this->plan());
    }

    public function test_check_clears_failures_of_subscriptions_back_to_active(): void
    {
        $this->company->forceFill(['payment_failed_at' => now()->subDays(2), 'payment_failed_attempts' => 2])->save();

        $this->artisan('billing:check-payments')->assertSuccessful();

        $company = $this->company->fresh();
        $this->assertSame(0, $company->payment_failed_attempts);
        $this->assertSame(Company::PLAN_PRO, $company->plan);
    }

    public function test_invoices_outside_the_subscription_are_ignored(): void
    {
        $this->invoice('invoice.payment_failed', 3, 'manual');
        $this->assertSame(0, $this->company->fresh()->payment_failed_attempts);
        $this->assertSame(Company::PLAN_PRO, $this->plan());
    }
}
