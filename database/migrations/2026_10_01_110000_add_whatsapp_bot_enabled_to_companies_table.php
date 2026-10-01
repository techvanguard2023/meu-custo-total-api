<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pausa geral do bot — diferente da pausa por conversa (whatsapp_handoffs,
 * 4h e volta sozinha): essa é por tempo indeterminado, até o lojista religar
 * manualmente na tela de Integrações. O WhatsApp continua conectado; só o
 * bot para de responder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('whatsapp_bot_enabled')->default(true)->after('whatsapp_session_name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('whatsapp_bot_enabled');
        });
    }
};
