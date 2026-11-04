<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cobranças da Stripe (assinaturas das lojas): receita, reembolso e a taxa da Stripe de cada uma
        Schema::create('platform_payments', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_charge_id')->unique();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stripe_customer_id')->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_refunded', 12, 2)->default(0);
            $table->decimal('fee', 12, 2)->nullable();
            $table->string('currency', 3);
            $table->string('description')->nullable();
            $table->string('receipt_url', 500)->nullable();
            $table->timestamp('paid_at')->index();
            $table->timestamps();
        });

        // Custos fixos informados à mão (servidor, licença da WAHA, domínios...)
        Schema::create('platform_costs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('BRL');
            $table->string('frequency', 10)->default('monthly'); // monthly | yearly
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
        });

        // Custos puxados por API, por mês (OpenAI em USD, tarifas avulsas da Stripe em BRL)
        Schema::create('platform_cost_months', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('month', 7); // YYYY-MM
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->timestamp('fetched_at');
            $table->unique(['provider', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_cost_months');
        Schema::dropIfExists('platform_costs');
        Schema::dropIfExists('platform_payments');
    }
};
