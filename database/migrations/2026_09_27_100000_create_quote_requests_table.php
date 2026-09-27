<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitação de orçamento de peça sob medida vinda do bot de WhatsApp.
 * Não é um Quote: chega sem preço, aguardando o vendedor avaliar e montar
 * o orçamento de verdade (com a calculadora) — aí sim vira um Quote, e
 * quote_id é preenchido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_description');
            $table->string('colors')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->json('photo_urls')->nullable();
            $table->text('notes')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('status')->default('pending');
            // Id da mensagem do WhatsApp que originou o pedido — evita duplicar
            // se o bot reenviar a mesma solicitação.
            $table->string('external_reference')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_requests');
    }
};
