<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preço de venda = preço sugerido pela ficha técnica (custo + margem), regravado sempre que o custo muda
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('auto_price')->default(false)->after('auto_cost');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('auto_price');
        });
    }
};
