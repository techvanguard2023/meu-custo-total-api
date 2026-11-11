<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Despesas fixas da loja nos parâmetros de precificação: lista [{name, amount}] por mês (aluguel, água, luz da
 * loja, internet, contador…). Somam com a folha e o imposto fixo e são rateadas do mesmo jeito (fixed_costs_mode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->json('fixed_expenses')->nullable()->after('employees');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('fixed_expenses');
        });
    }
};
