<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Domínio próprio do catálogo (Pro): ex. loja.minhaempresa.com.br
            $table->string('custom_domain', 253)->nullable()->unique();
            // Preenchido quando o DNS já aponta pra nós e o HTTPS está emitido — só então os links passam a usar o domínio
            $table->timestamp('custom_domain_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['custom_domain']);
            $table->dropColumn(['custom_domain', 'custom_domain_verified_at']);
        });
    }
};
