<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Como o bot do WhatsApp trata o pagamento quando fecha o pedido: "whatsapp" manda Pix e link na conversa (como
 * sempre foi); "portal" manda o cliente para "Meus pedidos" do catálogo, onde ele vê o pedido e paga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('whatsapp_bot_payment', 10)->default('whatsapp')->after('whatsapp_pix_key');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('whatsapp_bot_payment');
        });
    }
};
