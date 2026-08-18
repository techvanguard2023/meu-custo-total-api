<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subcategorias (ex: Chaveiros > Carros).
 *
 * Hierarquia limitada a dois níveis de propósito: profundidade livre complicaria
 * o filtro do catálogo e o caminho da URL sem ganho real neste domínio — a regra
 * de "o pai não pode ter pai" é aplicada no controller.
 *
 * A unicidade do nome passa a considerar o pai: "Carros" pode existir dentro de
 * Chaveiros e dentro de Miniaturas ao mesmo tempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('company_id')
                ->constrained('product_categories')->cascadeOnDelete();
        });

        // O índice novo precisa existir antes de remover o antigo: a FK de
        // company_id se apoia num índice que comece por essa coluna, e o MySQL
        // recusa remover o único que atende a ela.
        Schema::table('product_categories', function (Blueprint $table) {
            $table->unique(['company_id', 'parent_id', 'name']);
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->unique(['company_id', 'name']);
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'parent_id', 'name']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
