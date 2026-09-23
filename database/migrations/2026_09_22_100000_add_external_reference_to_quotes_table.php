<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            // Identificador que quem chama de fora (ex: n8n) manda junto — evita
            // duplicar o pedido se o webhook for reenviado. Não é único sozinho:
            // um pedido misto (pronto + sob encomenda) vira duas linhas com a
            // mesma referência.
            $table->string('external_reference', 100)->nullable()->after('review_token');
            $table->index(['company_id', 'external_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'external_reference']);
            $table->dropColumn('external_reference');
        });
    }
};
