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
        Schema::table('quotes', function (Blueprint $table) {
            // Carimbado a cada clique em "Pedir avaliação" — não só na primeira vez,
            // pois é a partir dele que se conta os 15 dias até poder pedir de novo.
            $table->timestamp('review_requested_at')->nullable()->after('review_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('review_requested_at');
        });
    }
};
