<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extrato de cashback do cliente: cada crédito (compra, avaliação, indicação, ajuste) e cada saída
        // (uso, vencimento, estorno). Créditos guardam quanto ainda resta e quando vencem — o uso consome
        // primeiro o que vence antes.
        Schema::create('cashback_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 10, 2); // positivo = crédito, negativo = saída
            $table->decimal('remaining', 10, 2)->nullable(); // só créditos
            $table->timestamp('expires_at')->nullable(); // só créditos
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'expires_at']);
            $table->index(['quote_id', 'type']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('cashback_enabled')->default(false);
            $table->decimal('cashback_purchase_percent', 5, 2)->default(2);
            $table->decimal('cashback_max_redeem_percent', 5, 2)->default(30);
            $table->unsignedSmallInteger('cashback_validity_months')->default(6);
        });

        // Quanto do pedido foi pago com cashback (já descontado do preço final)
        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('cashback_amount', 10, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('cashback_amount');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['cashback_enabled', 'cashback_purchase_percent', 'cashback_max_redeem_percent', 'cashback_validity_months']);
        });

        Schema::dropIfExists('cashback_entries');
    }
};
