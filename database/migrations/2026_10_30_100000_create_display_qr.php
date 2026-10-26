<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // QR Code da placa do expositor: leva pro WhatsApp ou pro catálogo — ou pra uma página com
        // os botões, quando ligada — sempre passando pelo sistema, que registra de qual expositor veio.
        Schema::table('displays', function (Blueprint $table) {
            $table->string('qr_destination', 20)->default('whatsapp')->after('notes'); // whatsapp | catalog
            $table->boolean('qr_page_enabled')->default(false)->after('qr_destination');
        });

        // scan = abriu o QR; click = botão da página; contact = conversa no WhatsApp que veio do QR
        Schema::create('display_qr_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('display_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('destination', 20)->nullable();
            // "Pessoa diferente" sem guardar dado pessoal: hash do aparelho + dia
            $table->string('visitor_hash', 64)->nullable();
            $table->string('phone_key', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['display_id', 'type', 'created_at']);
        });

        // Pedido que veio do QR de um expositor (diferente de display_id, que é a venda da consignação)
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('origin_display_id')->nullable()->after('display_source')->constrained('displays')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_display_id');
        });

        Schema::dropIfExists('display_qr_events');

        Schema::table('displays', function (Blueprint $table) {
            $table->dropColumn(['qr_destination', 'qr_page_enabled']);
        });
    }
};
