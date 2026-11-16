<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprovação de arte com opções: a loja manda várias artes e o cliente escolhe a que gostou ao aprovar.
 * Sem "choose_one", os arquivos são partes da mesma arte (frente e verso...) e o cliente aprova o conjunto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('art_proofs', function (Blueprint $table) {
            $table->boolean('choose_one')->default(false)->after('model_url');
            $table->foreignId('chosen_file_id')->nullable()->after('choose_one')->constrained('art_proof_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('art_proofs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chosen_file_id');
            $table->dropColumn('choose_one');
        });
    }
};
