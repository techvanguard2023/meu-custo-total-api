<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Material vendido direto ao cliente (opcional): a loja compra a embalagem por um preço (spool_cost) e vende por
 * outro (sale_price, na mesma base: o rolo/pacote inteiro). sale_price_per_unit é o preço por g ou por un, que o
 * orçamento usa no lugar de "custo + markup" quando o material entra no pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->decimal('sale_price', 10, 2)->nullable()->after('cost_per_g');
            $table->decimal('sale_price_per_unit', 12, 4)->nullable()->after('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn(['sale_price', 'sale_price_per_unit']);
        });
    }
};
