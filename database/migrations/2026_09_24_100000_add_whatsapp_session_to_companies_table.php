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
        Schema::table('companies', function (Blueprint $table) {
            // Nome da sessão da empresa no WAHA — gerado ao conectar (padrão
            // previsível: empresa-{id}), não editável pelo lojista.
            $table->string('whatsapp_session_name')->nullable()->after('catalog_accent_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('whatsapp_session_name');
        });
    }
};
