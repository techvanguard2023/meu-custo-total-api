<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contas de afiliado conectadas por API (hoje só a Shopee tem API oficial pra afiliados).
        // O Secret fica criptografado e nunca volta pra tela.
        Schema::create('affiliate_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20); // shopee
            $table->string('app_id', 64);
            $table->text('secret'); // criptografado (cast encrypted)
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'provider']);
        });

        Schema::table('affiliate_offers', function (Blueprint $table) {
            // Comissão informada pela loja de origem (Shopee: até X%) e quando o preço foi conferido pela API
            $table->decimal('commission_rate', 5, 2)->nullable()->after('original_price');
            $table->timestamp('price_checked_at')->nullable()->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_offers', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'price_checked_at']);
        });
        Schema::dropIfExists('affiliate_accounts');
    }
};
