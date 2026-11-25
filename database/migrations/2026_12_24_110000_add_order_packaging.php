<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embalagem do pedido (Configurações → Preços e custos): sacola, caixa de envio, cartão de agradecimento...
 * Vai UMA vez em cada pedido, não por unidade. Entra no custo (sai do lucro) e baixa o estoque na aprovação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->json('order_packaging')->nullable();
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('order_packaging_cost', 10, 2)->default(0)->after('fixed_costs_amount');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn('order_packaging_cost'));
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('order_packaging'));
    }
};
