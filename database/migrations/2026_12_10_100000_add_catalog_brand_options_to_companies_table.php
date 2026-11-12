<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Como a marca aparece no cabeçalho do catálogo público: logo (mostrar/ocultar e tamanho), nome da loja no
 * cabeçalho (mostrar/ocultar) e tamanho do nome grande da página (ou oculto). O padrão não muda lojas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('catalog_show_logo')->default(true)->after('catalog_layout');
            $table->string('catalog_logo_size', 10)->default('medium')->after('catalog_show_logo');
            $table->boolean('catalog_show_name')->default(true)->after('catalog_logo_size');
            $table->string('catalog_title_size', 10)->default('medium')->after('catalog_show_name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['catalog_show_logo', 'catalog_logo_size', 'catalog_show_name', 'catalog_title_size']);
        });
    }
};
