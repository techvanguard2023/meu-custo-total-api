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
        Schema::table('catalog_visits', function (Blueprint $table) {
            // Localização já convertida a partir do IP — o IP em si continua não
            // sendo gravado em lugar nenhum. Nulos quando não foi possível deduzir
            // (IP de rede local em desenvolvimento, serviço fora do ar etc.).
            $table->char('country_code', 2)->nullable()->after('visitor_hash');
            $table->string('region', 100)->nullable()->after('country_code');
            $table->string('city', 120)->nullable()->after('region');

            // Ranking de cidades por período é a consulta do dashboard
            $table->index(['company_id', 'visit_date', 'city'], 'catalog_visits_location_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_visits', function (Blueprint $table) {
            $table->dropIndex('catalog_visits_location_index');
            $table->dropColumn(['country_code', 'region', 'city']);
        });
    }
};
