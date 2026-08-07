<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quanto do orçamento já foi de fato recebido.
 *
 * Guarda o valor, não um rótulo: "recebi parte" sem o quanto não serve pro
 * dashboard somar nada. O status (não recebido / parcial / recebido) é derivado
 * disso no model, evitando o rótulo divergir do valor.
 *
 * Sem backfill de propósito: as vendas já aprovadas nascem com 0 e o valor
 * recebido é informado depois, uma a uma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('amount_paid', 10, 2)->default(0)->after('payment_method');
            $table->timestamp('paid_at')->nullable()->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['amount_paid', 'paid_at']);
        });
    }
};
