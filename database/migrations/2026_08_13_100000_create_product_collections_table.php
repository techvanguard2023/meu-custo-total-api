<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coleção de campanha (Natal, Réveillon, Volta às Aulas...) — página própria e
 * temporária que reúne produtos de qualquer categoria sob um mesmo tema.
 *
 * Diferente de categoria: categoria é estrutural (permanente, um produto tem uma
 * só) e coleção é promocional (temporária, um produto pode estar em várias ao
 * mesmo tempo, sem afetar a categoria real dele). Por isso não é subcategoria —
 * é uma tabela própria, sempre da empresa (sem conceito de "padrão do sistema").
 *
 * `active` é um interruptor manual — o lojista liga quando a campanha começa e
 * desliga quando termina, sem agendamento automático por data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('description', 500)->nullable();
            $table->string('banner_path')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('product_collection_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // A ordem de exibição na página da coleção é a ordem de inclusão
            // (linha mais antiga primeiro) — sem coluna de posição dedicada
            // pra manter o recurso simples; reordenar fica pra uma v2 se pedirem.
            // Nome do índice explícito: o gerado automaticamente pelo Laravel
            // passa dos 64 caracteres que o MySQL aceita como identificador.
            $table->unique(['product_collection_id', 'product_id'], 'collection_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_collection_product');
        Schema::dropIfExists('product_collections');
    }
};
