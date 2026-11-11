<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Funcionários e impostos nos parâmetros de precificação.
 * - employees: lista [{name, salary, charges_percent, hours_per_month}] (não é a Equipe de login).
 * - fixed_costs_mode: como a folha (+ imposto fixo mensal) entra no preço — none | revenue | hour | order.
 *   Padrão "none": lojas que já existem não têm os preços mudados sem escolher.
 * - tax_percent: imposto sobre a venda (Simples), embutido "por dentro" no preço.
 * - tax_fixed_monthly: imposto fixo por mês (DAS do MEI, contador), rateado junto da folha.
 * Nos pedidos ficam o imposto e o rateio de custos fixos de cada venda (o lucro já os desconta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->json('employees')->nullable();
            $table->string('fixed_costs_mode', 16)->default('none');
            $table->decimal('estimated_monthly_revenue', 12, 2)->nullable();
            $table->unsignedInteger('orders_per_month')->nullable();
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('tax_fixed_monthly', 10, 2)->default(0);
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('fixed_costs_amount', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['employees', 'fixed_costs_mode', 'estimated_monthly_revenue', 'orders_per_month', 'tax_percent', 'tax_fixed_monthly']);
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['tax_amount', 'fixed_costs_amount']);
        });
    }
};
