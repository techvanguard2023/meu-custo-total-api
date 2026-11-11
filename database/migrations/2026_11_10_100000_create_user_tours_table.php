<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tours do app já vistos, por pessoa: kind = full (tour completo, com o plano) ou preview (prévia, sem o plano).
 * Cada versão do tour é marcada à parte (ver config/tours.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tour_id', 40);
            $table->unsignedSmallInteger('version');
            $table->string('kind', 10);
            $table->timestamp('seen_at');

            $table->unique(['user_id', 'tour_id', 'version', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tours');
    }
};
