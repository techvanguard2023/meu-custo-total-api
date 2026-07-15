<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('quote_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1)->after('type');
            $table->decimal('unit_price', 10, 2)->default(0)->after('quantity');
            $table->decimal('unit_cost', 10, 2)->default(0)->after('unit_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['quantity', 'unit_price', 'unit_cost']);
        });
    }
};
