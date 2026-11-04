<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comunicados da plataforma: faixa dentro do app pra todas as lojas (ou só um plano)
        Schema::create('platform_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('message')->nullable();
            $table->string('level', 10)->default('info'); // info | warning | success
            $table->string('audience', 12)->default('all'); // all | free | essential | pro
            $table->string('link_url', 500)->nullable();
            $table->string('link_label', 40)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_announcements');
    }
};
