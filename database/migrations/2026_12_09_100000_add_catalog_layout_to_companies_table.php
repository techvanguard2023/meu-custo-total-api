<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layout do catálogo público escolhido pelo lojista (Company::CATALOG_LAYOUTS).
 * Padrão "vitrine" = o visual de sempre; as lojas que já existem não mudam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('catalog_layout', 20)->default('vitrine')->after('catalog_accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('catalog_layout');
        });
    }
};
