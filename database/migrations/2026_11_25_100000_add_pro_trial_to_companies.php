<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teste grátis do Pro (14 dias, sem cartão): separado da cortesia pra dar pra medir a conversão.
     * ended_at = o fim já foi processado (plano recalculado; na Fase 2, a mensagem de fim).
     * source: signup (cadastro), campaign (lojas Grátis antigas, uma vez) ou admin.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('pro_trial_started_at')->nullable()->after('admin_discount');
            $table->timestamp('pro_trial_ends_at')->nullable()->after('pro_trial_started_at');
            $table->timestamp('pro_trial_ended_at')->nullable()->after('pro_trial_ends_at');
            $table->string('pro_trial_source', 20)->nullable()->after('pro_trial_ended_at');
            $table->index('pro_trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['pro_trial_ends_at']);
            $table->dropColumn(['pro_trial_started_at', 'pro_trial_ends_at', 'pro_trial_ended_at', 'pro_trial_source']);
        });
    }
};
