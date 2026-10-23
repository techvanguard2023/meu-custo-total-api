<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Indicação (cashback Pro): cada cliente tem um código pro link do catálogo; quem chega por ele fica
        // ligado a quem indicou, e a 1ª compra entregue e paga premia os dois (uma vez só).
        Schema::table('customers', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->after('notes');
            $table->foreignId('referred_by_customer_id')->nullable()->after('referral_code')->constrained('customers')->nullOnDelete();
            $table->timestamp('referral_rewarded_at')->nullable()->after('referred_by_customer_id');
            $table->unique(['company_id', 'referral_code']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->decimal('cashback_referral_percent', 5, 2)->default(2)->after('cashback_review_percent');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('cashback_referral_percent');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'referral_code']);
            $table->dropConstrainedForeignId('referred_by_customer_id');
            $table->dropColumn(['referral_code', 'referral_rewarded_at']);
        });
    }
};
