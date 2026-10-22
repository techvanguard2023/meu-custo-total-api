<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Domínio próprio da área do parceiro (Pro): ex. parceiro.minhaempresa.com.br — abre direto na raiz
            $table->string('partner_domain', 253)->nullable()->unique()->after('custom_domain_verified_at');
            $table->timestamp('partner_domain_verified_at')->nullable()->after('partner_domain');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['partner_domain']);
            $table->dropColumn(['partner_domain', 'partner_domain_verified_at']);
        });
    }
};
