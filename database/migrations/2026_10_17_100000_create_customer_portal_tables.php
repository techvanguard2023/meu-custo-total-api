<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Meus pedidos" no catálogo: login sem senha, com código enviado pelo WhatsApp da loja.
        // O cliente é identificado pela chave do telefone (Customer::phoneKey), que junta cadastros duplicados.
        Schema::create('customer_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('phone_key', 20);
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'phone_key']);
        });

        Schema::create('customer_portal_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('phone_key', 20);
            // Só o hash do token fica no banco — o token em si vive no navegador do cliente
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // Quando cada pedido entrou em cada etapa da produção (linha do tempo que o cliente vê)
        Schema::create('quote_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 30);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['quote_id', 'stage']);
        });

        // Pedidos antigos: sem histórico, registra só a etapa atual (na data da última alteração)
        DB::statement("
            INSERT INTO quote_stage_events (quote_id, stage, created_at)
            SELECT id, production_status, COALESCE(updated_at, approved_at, created_at)
            FROM quotes
            WHERE production_status IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_stage_events');
        Schema::dropIfExists('customer_portal_sessions');
        Schema::dropIfExists('customer_login_codes');
    }
};
