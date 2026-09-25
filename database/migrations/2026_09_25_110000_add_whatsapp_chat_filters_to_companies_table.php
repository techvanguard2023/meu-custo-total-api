<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Quais tipos de conversa a sessão recebe (status, grupos, canais, listas de
            // transmissão) — nulo = todos ligados, o padrão da WAHA.
            $table->json('whatsapp_chat_filters')->nullable()->after('whatsapp_webhook_events');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('whatsapp_chat_filters');
        });
    }
};
