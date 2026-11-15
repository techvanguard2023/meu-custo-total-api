<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Como o produto é vendido: por unidade ou por pacote (o preço e o estoque são do pacote; "com N" é só
        // informação pro cliente). Quantidade mínima: em unidades ou em pacotes, conforme a venda. Produtos que já
        // existem seguem como estão: por unidade, sem mínimo.
        Schema::table('products', function (Blueprint $table) {
            $table->string('sale_unit', 10)->default('unit')->after('stock_quantity');
            $table->unsignedSmallInteger('pack_size')->nullable()->after('sale_unit');
            $table->string('pack_label', 20)->nullable()->after('pack_size');
            $table->unsignedInteger('min_quantity')->default(1)->after('pack_label');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sale_unit', 'pack_size', 'pack_label', 'min_quantity']);
        });
    }
};
