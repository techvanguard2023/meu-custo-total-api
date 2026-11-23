<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobrança da assinatura recusada: a loja segue no plano enquanto a Stripe tenta de novo. payment_failed_at =
 * primeira recusa desta fatura; payment_failed_attempts = tentativas recusadas (attempt_count da fatura).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('payment_failed_at')->nullable()->after('stripe_id');
            $table->unsignedTinyInteger('payment_failed_attempts')->default(0)->after('payment_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['payment_failed_at', 'payment_failed_attempts']);
        });
    }
};
