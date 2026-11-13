<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumo diário das entregas pela Alexa: um lembrete por pessoa, criado na conversa com a skill e com o texto
 * atualizado de hora em hora (a Amazon só deixa criar lembrete durante a conversa; fora dela, só alterar ou apagar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alexa_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Identificador da pessoa na Alexa (amzn1.ask.account...) — destino das mensagens da Amazon
            $table->string('alexa_user_id', 512);
            // Região da API da Alexa de onde a pessoa falou (https://api.amazonalexa.com, ...)
            $table->string('api_endpoint');
            $table->string('alert_token');
            $table->string('remind_at', 5);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alexa_reminders');
    }
};
