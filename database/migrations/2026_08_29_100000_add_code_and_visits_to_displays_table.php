<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código de identificação do expositor (o lojista costuma etiquetar o móvel
 * fisicamente) + o registro de cada visita (reposição ou conferência), que é
 * onde a foto de evidência fica pendurada — uma por visita, não por linha de
 * produto, porque é uma foto do expositor inteiro, não de um item isolado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('displays', function (Blueprint $table) {
            $table->string('code', 60)->nullable()->after('name');
        });

        Schema::create('display_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('display_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // restock | reconciliation
            $table->string('photo_path')->nullable();
            // Preenchido só nas visitas de conferência — liga a visita à venda gerada.
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('display_stock_movements', function (Blueprint $table) {
            $table->foreignId('display_visit_id')->nullable()->after('display_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('display_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('display_visit_id');
        });

        Schema::dropIfExists('display_visits');

        Schema::table('displays', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
