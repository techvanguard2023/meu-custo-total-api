<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mensagem do admin pra UMA loja (ficha da loja → "Enviar mensagem"): o mesmo aviso dos comunicados, só que
     * com company_id. read_at = alguém da loja fechou o aviso; whatsapp_status = como foi o WhatsApp pro dono.
     */
    public function up(): void
    {
        Schema::table('platform_announcements', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable()->after('active');
            $table->string('whatsapp_status', 255)->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('platform_announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['read_at', 'whatsapp_status']);
        });
    }
};
