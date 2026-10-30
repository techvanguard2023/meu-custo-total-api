<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // owner (dono: tudo) | sales (vendas) | production (produção) — quem já existe é dono
            $table->string('role', 20)->default('owner')->after('company_id');
            // Convite da equipe: enquanto não aceito, a pessoa ainda não criou a senha
            $table->timestamp('invited_at')->nullable()->after('role');
            $table->timestamp('invitation_accepted_at')->nullable()->after('invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'invited_at', 'invitation_accepted_at']);
        });
    }
};
