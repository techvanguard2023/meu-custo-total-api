<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->unsignedSmallInteger('delivery_days')->nullable()->after('discount_amount');
            $table->timestamp('approved_at')->nullable()->after('production_order');
        });

        // Vendas existentes: usa a última atualização como melhor aproximação da aprovação
        DB::table('quotes')->where('status', 'approved')->update(['approved_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['delivery_days', 'approved_at']);
        });
    }
};
