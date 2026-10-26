<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cores da variação em hexadecimal (uma ou mais — ex: "Verde + Branco" = ["#16A34A", "#FFFFFF"]).
        // O catálogo mostra a bolinha da cor ao lado do nome e pinta o botão da opção escolhida.
        Schema::table('product_variations', function (Blueprint $table) {
            $table->json('color_hexes')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            $table->dropColumn('color_hexes');
        });
    }
};
