<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Por etapa do Kanban: liga/desliga e texto do aviso ao cliente — nulo = padrão.
            $table->json('whatsapp_status_notifications')->nullable()->after('whatsapp_chat_filters');
        });

        Schema::table('quotes', function (Blueprint $table) {
            // Etapas que já geraram aviso ao cliente — evita repetir ao arrastar o cartão de volta.
            $table->json('notified_stages')->nullable()->after('review_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn('notified_stages'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('whatsapp_status_notifications'));
    }
};
