<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PagBank como segundo gateway:
 *  - customers.tax_id: CPF ou CNPJ do cliente (opcional; só dígitos). O Pix do PagBank exige o documento do comprador.
 *  - payment_charges.meta: dados do provedor que não cabem nos ids padrão (ex.: no PagBank, o link é um checkout
 *    CHEC_... e o pagamento vira um pedido ORDE_... que só chega pelo webhook).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('tax_id', 14)->nullable()->after('email');
        });
        Schema::table('payment_charges', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('ticket_url');
        });
    }

    public function down(): void
    {
        Schema::table('payment_charges', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('tax_id');
        });
    }
};
