<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Só dígitos, com DDI — a chave da conversa do cliente.
            $table->string('phone', 30);
            $table->string('customer_name')->nullable();
            $table->string('reason', 500)->nullable();
            // Enquanto no futuro, o bot fica calado nessa conversa (atendimento humano).
            $table->timestamp('paused_until');
            $table->timestamps();

            $table->unique(['company_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_handoffs');
    }
};
