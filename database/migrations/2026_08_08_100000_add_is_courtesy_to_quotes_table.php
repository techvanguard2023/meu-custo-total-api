<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venda dada como cortesia (brinde, amostra, troca por divulgação).
 *
 * Precisa ser uma coluna própria porque não dá para derivar de `amount_paid`:
 * cortesia e "ainda não recebi" são ambos zero recebido, mas significam coisas
 * opostas — uma nunca vai entrar, a outra está pendente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->boolean('is_courtesy')->default(false)->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('is_courtesy');
        });
    }
};
