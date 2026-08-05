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
        Schema::create('product_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Atributos descritivos — ao menos um preenchido (validado no controller).
            // São rótulos livres ("Azul", "P", "50g") porque cada negócio nomeia do seu jeito.
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('weight')->nullable();

            $table->string('sku')->nullable();
            // Nulos = herdam do produto. Evita repetir o mesmo valor em toda variação
            // quando só algumas fogem do padrão (ex: só o tamanho G custa mais caro).
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->decimal('cost', 10, 2)->nullable();

            // Quando o produto tem variações, o estoque real passa a ser este — o
            // products.stock_quantity deixa de ser usado nas vendas desse produto.
            $table->integer('stock_quantity')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variations');
    }
};
