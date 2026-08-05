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
        Schema::create('catalog_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Impressão digital anônima e irreversível (sha256 de IP + navegador + sal do dia).
            // Como o sal muda diariamente, o mesmo visitante gera hashes diferentes a cada dia:
            // serve pra contar únicos no dia, mas não permite rastrear alguém ao longo do tempo.
            $table->char('visitor_hash', 64);
            $table->date('visit_date');
            $table->timestamp('visited_at');

            // Agregações são sempre por empresa + intervalo de datas
            $table->index(['company_id', 'visit_date']);
            // Cobre a contagem de visitantes distintos por dia sem tocar na tabela
            $table->index(['company_id', 'visit_date', 'visitor_hash'], 'catalog_visits_unique_count_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_visits');
    }
};
