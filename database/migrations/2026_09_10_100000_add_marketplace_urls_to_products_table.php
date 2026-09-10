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
        Schema::table('products', function (Blueprint $table) {
            $table->string('marketplace_mercado_livre_url', 2048)->nullable()->after('model_3d_url');
            $table->string('marketplace_shopee_url', 2048)->nullable()->after('marketplace_mercado_livre_url');
            $table->string('marketplace_amazon_url', 2048)->nullable()->after('marketplace_shopee_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['marketplace_mercado_livre_url', 'marketplace_shopee_url', 'marketplace_amazon_url']);
        });
    }
};
