<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega dividida: o pedido tem vários itens, alguns prontos e outros esperando insumo. A loja entrega o que já
 * está pronto e registra aqui (cada entrega, com os itens e as quantidades); o item guarda quanto já saiu.
 * Valores e estoque não mudam — o estoque já baixou na aprovação e o pedido continua um só.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->unsignedInteger('delivered_quantity')->default(0)->after('quantity');
        });

        Schema::create('quote_partial_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // [{item_id, description, quantity}] — a descrição fica copiada para o histórico
            $table->json('items');
            $table->string('note', 500)->nullable();
            $table->boolean('customer_notified')->default(false);
            $table->timestamps();

            $table->index(['quote_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_partial_deliveries');
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn('delivered_quantity');
        });
    }
};
