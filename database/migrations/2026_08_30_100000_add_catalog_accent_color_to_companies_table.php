<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cor de destaque do catálogo público — aplicada em botões, badges e links,
 * nunca no fundo nem no texto neutro. Nula = usa a cor padrão do sistema (indigo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('catalog_accent_color', 7)->nullable()->after('catalog_about');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('catalog_accent_color');
        });
    }
};
