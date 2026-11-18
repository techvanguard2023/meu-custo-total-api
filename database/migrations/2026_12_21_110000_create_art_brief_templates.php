<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modelos de briefing: conjuntos de perguntas com nome ("Topo de bolo", "Caneca") que a loja liga a cada produto.
        Schema::create('art_brief_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('questions');
            $table->timestamps();
        });

        // Produto que pede briefing (o bot manda o link depois do pedido): com as perguntas de um modelo ou, sem modelo,
        // as perguntas padrão da loja
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('art_brief_enabled')->default(false);
            $table->foreignId('art_brief_template_id')->nullable()->constrained('art_brief_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('art_brief_template_id');
            $table->dropColumn('art_brief_enabled');
        });
        Schema::dropIfExists('art_brief_templates');
    }
};
