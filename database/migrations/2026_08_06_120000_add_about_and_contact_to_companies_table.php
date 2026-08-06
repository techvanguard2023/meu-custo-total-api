<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conteúdo das seções "Quem Somos" e "Contatos" do catálogo público.
 *
 * São campos separados do `email`/`phone` da empresa de propósito: aqueles são
 * dados de cadastro da conta (muita gente usa e-mail pessoal) e publicá-los
 * automaticamente no catálogo seria expor o que o dono não escolheu expor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('catalog_about')->nullable()->after('catalog_disclaimer');
            $table->string('catalog_address')->nullable()->after('catalog_about');
            $table->string('catalog_hours')->nullable()->after('catalog_address');
            $table->string('catalog_email')->nullable()->after('catalog_hours');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['catalog_about', 'catalog_address', 'catalog_hours', 'catalog_email']);
        });
    }
};
