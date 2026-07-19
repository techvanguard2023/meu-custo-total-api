<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            // Consumo por peça (grama ou unidade) do material neste item; `quantity` continua
            // sendo a quantidade do pedido, igual ao padrão já usado nos itens de produto.
            $table->decimal('unit_weight', 12, 3)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_id');
            $table->dropColumn('unit_weight');
        });
    }
};
