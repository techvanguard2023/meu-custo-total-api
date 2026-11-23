<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domínios próprios ao sair do Pro (assinatura suspensa, cancelada, fim do teste): ficam guardados e parados desde
 * domains_suspended_at. Voltando ao Pro, voltam a valer sozinhos; depois de 30 dias fora, são liberados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('domains_suspended_at')->nullable()->after('offers_domain_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('domains_suspended_at');
        });
    }
};
