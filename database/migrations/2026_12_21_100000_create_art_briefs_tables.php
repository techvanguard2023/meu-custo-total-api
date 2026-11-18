<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Briefing da arte: o cliente responde, por um link, o que a loja precisa saber para criar a arte (cores, texto,
        // tema, nomes, referências...). As perguntas vêm do nicho da loja e o lojista ajusta (companies.art_brief_questions).
        Schema::create('art_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('token', 48)->unique();
            // pending (aguardando o cliente) · submitted (respondido)
            $table->string('status', 20)->default('pending');
            // Perguntas no momento do envio: mudar o briefing da loja depois não muda o que o cliente viu nem as respostas
            $table->json('questions');
            $table->json('answers')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            // A loja abriu as respostas (até lá conta no aviso do menu)
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->index(['quote_id', 'id']);
            $table->index(['company_id', 'status', 'seen_at']);
        });

        Schema::create('art_brief_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('art_brief_id')->constrained()->cascadeOnDelete();
            $table->string('question_key', 40);
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            // null = as perguntas padrão do nicho da loja
            $table->json('art_brief_questions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('art_brief_questions');
        });
        Schema::dropIfExists('art_brief_files');
        Schema::dropIfExists('art_briefs');
    }
};
