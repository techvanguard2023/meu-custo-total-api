<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Código da oferta no link do produto (?o=CODIGO): o catálogo registra o clique ao abrir
        Schema::table('offer_posts', function (Blueprint $table) {
            $table->string('code', 12)->nullable()->unique()->after('origin');
        });

        Schema::create('offer_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // "Pessoa diferente" sem guardar dado pessoal: hash do aparelho no dia
            $table->string('visitor_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['offer_post_id', 'visitor_hash']);
        });

        // Texto próprio da oferta (escrito à mão ou gerado por IA e revisado): entra no lugar da descrição.
        // Preço e link continuam montados pelo sistema.
        Schema::table('products', function (Blueprint $table) {
            $table->text('offer_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('offer_text'));
        Schema::dropIfExists('offer_clicks');
        Schema::table('offer_posts', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
