<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O Billable do Cashier neste projeto é a Company, então a FK da tabela
     * de assinaturas precisa ser company_id (a migration publicada cria user_id).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('subscriptions', 'user_id')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'stripe_status']);
            $table->renameColumn('user_id', 'company_id');
            $table->index(['company_id', 'stripe_status']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('subscriptions', 'company_id')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'stripe_status']);
            $table->renameColumn('company_id', 'user_id');
            $table->index(['user_id', 'stripe_status']);
        });
    }
};
