<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Relatórios, Dashboard e Vendas filtram as vendas aprovadas da empresa pela data de aprovação. */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'approved_at'], 'quotes_company_status_approved_index');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex('quotes_company_status_approved_index');
        });
    }
};
