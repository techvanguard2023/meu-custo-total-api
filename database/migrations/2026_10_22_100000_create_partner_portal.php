<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Área do parceiro (dono da loja que tem o expositor): mesmo login por código no WhatsApp do
        // "Meus pedidos", separado por escopo — a sessão de cliente não abre a área do parceiro e vice-versa.
        Schema::table('customer_login_codes', function (Blueprint $table) {
            $table->string('scope', 20)->default('customer')->after('company_id');
        });

        Schema::table('customer_portal_sessions', function (Blueprint $table) {
            $table->string('scope', 20)->default('customer')->after('company_id');
        });

        // De onde veio a venda do expositor: apurada na conferência ou lançada pelo parceiro
        // (essa fica "a receber" até o repasse).
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('display_source', 20)->nullable()->after('display_commission_amount');
        });

        DB::table('quotes')->whereNotNull('display_id')->update(['display_source' => 'reconciliation']);
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('display_source');
        });

        Schema::table('customer_portal_sessions', function (Blueprint $table) {
            $table->dropColumn('scope');
        });

        Schema::table('customer_login_codes', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
