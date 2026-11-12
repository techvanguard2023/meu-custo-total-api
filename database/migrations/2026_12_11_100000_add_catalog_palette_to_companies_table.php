<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paleta do catálogo público além da cor de destaque (catalog_accent_color): secundária, fundo da página,
 * texto e cabeçalho. Valem para qualquer layout; nulo = a cor própria de cada layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('catalog_secondary_color', 7)->nullable()->after('catalog_title_size');
            $table->string('catalog_background_color', 7)->nullable()->after('catalog_secondary_color');
            $table->string('catalog_text_color', 7)->nullable()->after('catalog_background_color');
            $table->string('catalog_header_color', 7)->nullable()->after('catalog_text_color');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['catalog_secondary_color', 'catalog_background_color', 'catalog_text_color', 'catalog_header_color']);
        });
    }
};
