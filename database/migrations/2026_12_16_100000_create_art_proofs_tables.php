<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prova de arte: a loja manda arte/modelo pro cliente conferir e aprovar antes de produzir. Cada envio é uma
        // versão (a nova substitui a anterior); o termo lido e quem aprovou (nome, data, IP) ficam guardados como prova.
        Schema::create('art_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('token', 48)->unique();
            $table->unsignedSmallInteger('version')->default(1);
            // pending (aguardando) · approved · changes_requested (cliente pediu ajuste) · superseded (veio uma versão nova)
            $table->string('status', 20)->default('pending');
            $table->text('message')->nullable();
            $table->string('model_url', 500)->nullable();
            // Texto do termo no momento do envio — editar o termo da loja depois não muda o que o cliente leu
            $table->text('terms');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('approver_name', 120)->nullable();
            $table->string('approver_ip', 45)->nullable();
            $table->string('approver_user_agent', 300)->nullable();
            $table->text('customer_comment')->nullable();
            $table->timestamps();

            $table->index(['quote_id', 'version']);
        });

        Schema::create('art_proof_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('art_proof_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Termo de aprovação da loja (em branco = o texto padrão do sistema)
        Schema::table('companies', function (Blueprint $table) {
            $table->text('art_approval_terms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('art_approval_terms');
        });
        Schema::dropIfExists('art_proof_files');
        Schema::dropIfExists('art_proofs');
    }
};
