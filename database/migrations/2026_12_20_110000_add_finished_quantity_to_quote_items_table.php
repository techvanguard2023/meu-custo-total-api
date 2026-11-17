<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega dividida, passo "pronto": o item fica Finalizado (pronto para retirar ou enviar) antes de sair.
 * finished_quantity conta as unidades prontas (as entregues também contam como prontas). O histórico guarda
 * se o registro foi de itens prontos (finished) ou entregues (delivered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->unsignedInteger('finished_quantity')->default(0)->after('quantity');
        });
        Schema::table('quote_partial_deliveries', function (Blueprint $table) {
            $table->string('kind', 10)->default('delivered')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('quote_partial_deliveries', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn('finished_quantity');
        });
    }
};
