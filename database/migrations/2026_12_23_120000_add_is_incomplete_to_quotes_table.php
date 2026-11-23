<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamento salvo incompleto (só o nome do item, só o cliente...): dá para salvar e voltar depois, mas não para
 * aprovar enquanto não tiver um item (produto pronto ou peça com tempo de produção / material).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->boolean('is_incomplete')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('is_incomplete');
        });
    }
};
