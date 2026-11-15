<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Grades de opções do produto: "Acabamento" (fosco, brilho, verniz), "Embalagem" (presente, simples)...
        // Cada opção soma um valor ao preço (e ao custo) de cada unidade. Single = escolhe uma; multiple = pode várias.
        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 8)->default('single');
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('price_delta', 10, 2)->default(0);
            $table->decimal('cost_delta', 10, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // O que foi escolhido na venda, guardado como foto do momento (nome e valores): apagar ou mudar a opção
        // depois não altera pedidos antigos.
        Schema::table('quote_items', function (Blueprint $table) {
            $table->json('options')->nullable()->after('product_variation_id');
        });
    }

    public function down(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn('options');
        });
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_option_groups');
    }
};
