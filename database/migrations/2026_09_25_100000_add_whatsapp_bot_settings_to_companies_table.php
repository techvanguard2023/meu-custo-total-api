<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Webhook opcional da sessão do WhatsApp + quais eventos enviar a ele.
            $table->string('whatsapp_webhook_url', 2048)->nullable()->after('whatsapp_session_name');
            $table->json('whatsapp_webhook_events')->nullable()->after('whatsapp_webhook_url');
            // Instruções do chatbot e dados de pagamento que ele repassa ao cliente —
            // o n8n lê tudo isso via GET /whatsapp/settings.
            $table->text('whatsapp_bot_prompt')->nullable()->after('whatsapp_webhook_events');
            $table->string('whatsapp_payment_link', 2048)->nullable()->after('whatsapp_bot_prompt');
            $table->text('whatsapp_pix_key')->nullable()->after('whatsapp_payment_link');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_webhook_url', 'whatsapp_webhook_events', 'whatsapp_bot_prompt',
                'whatsapp_payment_link', 'whatsapp_pix_key',
            ]);
        });
    }
};
