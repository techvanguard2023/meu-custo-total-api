<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Última cobrança enviada ao cliente (botão "Cobrar" das vendas entregues a receber)
        Schema::table('quotes', function (Blueprint $table) {
            $table->timestamp('charge_requested_at')->nullable()->after('review_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('charge_requested_at');
        });
    }
};
