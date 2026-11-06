<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 da opinião dos lojistas: avaliação do atendimento (uma por chamado de suporte) e o motivo do cancelamento
 * da assinatura (kind cancel_exit, com o id da assinatura no context).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_feedback', function (Blueprint $table) {
            $table->foreignId('support_ticket_id')->nullable()->after('user_id')->constrained('support_tickets')->nullOnDelete();
            $table->unique('support_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_feedback', function (Blueprint $table) {
            $table->dropForeign(['support_ticket_id']);
            $table->dropUnique(['support_ticket_id']);
            $table->dropColumn('support_ticket_id');
        });
    }
};
