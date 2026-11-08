<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagamento online (Fase 2):
 *  - settings.payment_rules: Pix que o bot manda no pedido ({bot_pix: total|deposit|off, deposit_percent});
 *  - payment_charges.allocations: um Pix/link pode quitar mais de uma parte do mesmo pedido (pronta entrega +
 *    sob encomenda) — [{quote_id, amount}]; origin: quem gerou (app, bot, portal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->json('payment_rules')->nullable()->after('delivery_rules');
        });
        Schema::table('payment_charges', function (Blueprint $table) {
            $table->json('allocations')->nullable()->after('amount');
            $table->string('origin', 10)->default('app')->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('payment_charges', fn (Blueprint $table) => $table->dropColumn(['allocations', 'origin']));
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('payment_rules'));
    }
};
