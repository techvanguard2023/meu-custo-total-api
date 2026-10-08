<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preço por quantidade: faixas por produto ([{min_quantity, type: fixed|percent, value}])
        Schema::table('products', function (Blueprint $table) {
            $table->json('price_tiers')->nullable()->after('discount_percent');
        });

        // Desconto por valor do pedido, geral da loja ([{min_total, percent}])
        Schema::table('settings', function (Blueprint $table) {
            $table->json('order_discount_tiers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('price_tiers'));
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('order_discount_tiers'));
    }
};
