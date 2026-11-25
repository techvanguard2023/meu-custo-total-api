<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR Codes, fase 2: cupom que já vai aplicado, cor e logo no QR, chamada da arte para imprimir, e a origem das
 * vendas (carrinho enviado do catálogo e pedido criado pelo bot ligados ao QR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('whatsapp_message')->constrained()->nullOnDelete();
            $table->string('color', 7)->nullable()->after('active');
            $table->boolean('with_logo')->default(false)->after('color');
            $table->string('headline', 80)->nullable()->after('with_logo');
        });

        Schema::table('qr_code_events', function (Blueprint $table) {
            // kind "cart": carrinho enviado pelo WhatsApp a partir do catálogo aberto pelo QR (valor do carrinho)
            $table->decimal('amount', 10, 2)->nullable()->after('kind');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('origin_qr_code_id')->nullable()->after('origin_display_id')->constrained('qr_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $table) => $table->dropConstrainedForeignId('origin_qr_code_id'));
        Schema::table('qr_code_events', fn (Blueprint $table) => $table->dropColumn('amount'));
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['color', 'with_logo', 'headline']);
        });
    }
};
