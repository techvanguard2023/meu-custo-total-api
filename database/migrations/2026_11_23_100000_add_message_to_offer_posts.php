<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Recado de texto livre nos grupos/canais de ofertas (origin = social: os links das redes da loja). */
    public function up(): void
    {
        Schema::table('offer_posts', function (Blueprint $table) {
            $table->text('message')->nullable()->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('offer_posts', function (Blueprint $table) {
            $table->dropColumn('message');
        });
    }
};
