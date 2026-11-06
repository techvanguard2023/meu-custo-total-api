<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opinião dos lojistas sobre a plataforma: NPS (0–10) e "por que não assinou" no fim do teste grátis.
 * users.feedback_state guarda quando a pessoa fechou a pergunta ("agora não"), pra não insistir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20); // nps | trial_exit
            $table->unsignedTinyInteger('score')->nullable(); // NPS 0–10
            $table->string('reason', 30)->nullable(); // motivo do fim do teste
            $table->text('comment')->nullable();
            $table->string('channel', 10)->default('app'); // app | email
            $table->json('context')->nullable(); // plano, dias de uso, origem do teste... no momento da resposta
            $table->timestamp('followed_up_at')->nullable(); // admin: "conversei com a loja"
            $table->string('follow_up_note', 500)->nullable();
            $table->timestamps();

            $table->index(['kind', 'created_at']);
            $table->index(['company_id', 'kind']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('feedback_state')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('feedback_state');
        });
        Schema::dropIfExists('platform_feedback');
    }
};
