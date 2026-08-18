<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorias próprias por empresa.
 *
 * `company_id` nulo = categoria padrão do sistema, visível para todo mundo (as
 * que já existem continuam assim). Preenchido = categoria criada pela empresa,
 * que só ela enxerga — sem isso, a categoria de um lojista apareceria na lista
 * de todos os outros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            // Uma empresa não repete o mesmo nome; as padrão (company_id nulo)
            // não colidem entre si porque NULL não conta em índice único no MySQL.
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
