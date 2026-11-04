<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Último uso do painel (atualizado no máximo a cada 5 min) — "lojas ativas" e último acesso no admin
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('remember_token')->index();
        });

        // Ações da área admin da plataforma sobre a loja
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable();
            $table->string('blocked_reason')->nullable();
            // Plano de cortesia: vale por cima da assinatura Stripe (o maior dos dois) até a data
            $table->string('comp_plan', 20)->nullable();
            $table->date('comp_until')->nullable();
            $table->string('comp_note')->nullable();
            $table->text('admin_notes')->nullable();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_email');
            $table->string('action', 60);
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['blocked_at', 'blocked_reason', 'comp_plan', 'comp_until', 'comp_note', 'admin_notes']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_seen_at'));
    }
};
