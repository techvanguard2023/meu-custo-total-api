<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            // Null = estoque não rastreado para este material (não bloqueia nem baixa nada).
            // Grama para filamentos, unidade para os demais insumos (mesma dualidade de cost_per_g).
            $table->decimal('stock_quantity', 12, 2)->nullable()->after('cost_per_g');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });
    }
};
