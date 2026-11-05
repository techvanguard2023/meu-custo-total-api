<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desconto na assinatura dado pelo admin (ficha da loja → "Desconto"): cupom criado na Stripe só pra esta loja.
     * status applied = já está na assinatura; pending = a loja não assina ainda e o cupom entra no próximo checkout.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('admin_discount')->nullable()->after('comp_note');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('admin_discount');
        });
    }
};
