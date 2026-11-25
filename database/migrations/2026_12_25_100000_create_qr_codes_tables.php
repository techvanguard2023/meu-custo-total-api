<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR Codes da loja (Marketing → QR Codes) para posts, banners, panfletos... O QR aponta para o link curto da
 * loja (/qr/{codigo}), que conta a leitura e leva ao destino — trocar o destino não exige reimprimir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // Parte final do link curto (/qr/natal): única por loja
            $table->string('code', 40);
            // Onde vai ser usado (Instagram, panfleto...) — só para comparar os canais
            $table->string('channel', 20)->default('other');
            // catalog | product | category | collection | whatsapp | instagram | url
            $table->string('destination_type', 20)->default('catalog');
            $table->unsignedBigInteger('destination_id')->nullable();
            $table->string('destination_url', 2048)->nullable();
            $table->string('whatsapp_message', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('qr_code_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qr_code_id')->constrained()->cascadeOnDelete();
            // scan = leu o QR; link = abriu o link curto copiado (bio, stories)
            $table->string('kind', 10)->default('scan');
            // Visitante único do dia (IP + navegador embaralhados) — nada pessoal fica guardado
            $table->string('visitor_hash', 64)->nullable();
            $table->string('device', 10)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('region', 80)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['qr_code_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_code_events');
        Schema::dropIfExists('qr_codes');
    }
};
