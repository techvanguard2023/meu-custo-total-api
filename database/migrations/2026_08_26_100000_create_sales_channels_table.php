<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal de venda (Mercado Livre, Shopee...) e a taxa que ele cobra por cima do
 * preço — comissão percentual + uma taxa fixa opcional (comum em itens de
 * valor baixo). O lojista digita o que vê no relatório de repasse do próprio
 * marketplace; o sistema não tenta adivinhar ou buscar isso de fora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->decimal('fixed_fee', 10, 2)->default(0);
            // Desativar em vez de excluir preserva o canal em vendas antigas.
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('sales_channel_id')->nullable()->after('discount_amount')
                ->constrained()->nullOnDelete();
            // Guardado no momento da venda: se a taxa do canal mudar depois, o
            // histórico não pode reescrever silenciosamente a margem já realizada.
            $table->decimal('channel_fee_amount', 10, 2)->default(0)->after('sales_channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_channel_id');
            $table->dropColumn('channel_fee_amount');
        });

        Schema::dropIfExists('sales_channels');
    }
};
