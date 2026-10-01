<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prazo de produção de item sob encomenda — mostrado no catálogo público e
 * usado pelo bot de WhatsApp pra responder "qual o prazo desse item?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('lead_time_days')->nullable()->after('made_to_order');
            // 'business' (dias úteis) ou 'calendar' (dias corridos)
            $table->string('lead_time_days_type', 20)->nullable()->after('lead_time_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['lead_time_days', 'lead_time_days_type']);
        });
    }
};
