<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda (Fase 3): regras de entrega da loja — dias em que trabalha, antecedência mínima, limite de pedidos por
 * dia, datas bloqueadas (férias, feriado), formas que oferece e se o cliente escolhe a data no catálogo/bot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->json('delivery_rules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('delivery_rules');
        });
    }
};
