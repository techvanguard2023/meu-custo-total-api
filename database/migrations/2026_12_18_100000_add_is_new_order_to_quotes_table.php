<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido novo que chegou sem o lojista lançar (bot do WhatsApp): bolinha em "Produção" no menu e selo "Novo" no card,
 * até o lojista abrir a tela de Produção. Mesmo jeito do unread_by_user dos chamados do suporte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->boolean('is_new_order')->default(false)->after('production_order');
            $table->index(['company_id', 'is_new_order']);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_new_order']);
            $table->dropColumn('is_new_order');
        });
    }
};
