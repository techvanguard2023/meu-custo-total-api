<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa "ativo" (usável no sistema — orçamentos, Caixa, expositores) de
 * "aparece no catálogo público". Produto pode ficar ativo pra vender por
 * fora (ex: marketplace, encomenda combinada) sem entrar no catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('show_in_catalog')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('show_in_catalog');
        });
    }
};
