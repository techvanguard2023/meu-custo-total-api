<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cashback por avaliar a compra (% do valor do pedido, uma vez por pedido)
        Schema::table('settings', function (Blueprint $table) {
            $table->decimal('cashback_review_percent', 5, 2)->default(1)->after('cashback_purchase_percent');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('cashback_review_percent');
        });
    }
};
