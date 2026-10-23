<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avisos de cashback em massa (crédito retroativo pros clientes atuais): saem aos poucos pelo
        // WhatsApp da loja — um por minuto — pra não arriscar o número ser bloqueado por envio em massa.
        Schema::create('cashback_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->timestamp('send_after');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'sent_at', 'failed_at', 'send_after'], 'cashback_notifications_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_notifications');
    }
};
