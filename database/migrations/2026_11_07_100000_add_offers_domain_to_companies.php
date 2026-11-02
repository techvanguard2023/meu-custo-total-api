<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Domínio próprio dos links de ofertas (grupo.empresa.com.br/ofertas/{slug}) — Pro, como os outros dois
        Schema::table('companies', function (Blueprint $table) {
            $table->string('offers_domain')->nullable()->unique()->after('partner_domain_verified_at');
            $table->timestamp('offers_domain_verified_at')->nullable()->after('offers_domain');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['offers_domain']);
            $table->dropColumn(['offers_domain', 'offers_domain_verified_at']);
        });
    }
};
