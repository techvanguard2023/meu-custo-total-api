<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agenda das ofertas (uma por loja, vale pra todas as séries ativas): nos dias/horários escolhidos,
        // os N produtos marcados há mais tempo sem divulgar entram na fila (rodízio)
        Schema::create('offer_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->json('days');   // 1 = segunda … 7 = domingo (ISO)
            $table->json('times');  // ["10:00", "19:00"], no fuso da loja
            $table->unsignedTinyInteger('products_per_run')->default(3);
            // Produto sem estoque (e que não é sob encomenda) fica de fora
            $table->boolean('only_in_stock')->default(true);
            // Último horário já disparado (ex: "2026-11-03 10:00"), pra não repetir o mesmo
            $table->string('last_slot', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_schedules');
    }
};
