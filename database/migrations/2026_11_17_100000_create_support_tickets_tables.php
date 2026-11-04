<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chamados de suporte: a loja abre pelo app ("Ajuda e suporte") e a plataforma responde na área admin
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // quem abriu
            $table->string('category', 20); // problem | question | suggestion | billing
            $table->string('subject', 120);
            $table->string('status', 20)->default('open'); // open (com o suporte) | answered (com a loja) | resolved
            $table->string('page_url', 500)->nullable(); // tela em que a pessoa estava ao abrir
            $table->json('context')->nullable(); // navegador, tamanho da tela, versão do app
            $table->boolean('unread_by_user')->default(false);
            $table->boolean('unread_by_admin')->default(true);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('from_admin')->default(false);
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
