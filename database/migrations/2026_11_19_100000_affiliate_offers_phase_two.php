<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agenda própria dos achadinhos: uma agenda por loja E por tipo (produtos da loja / achadinhos)
        Schema::table('offer_schedules', function (Blueprint $table) {
            $table->string('purpose', 10)->default('store')->after('company_id');
            // O índice novo começa por company_id e passa a servir a chave estrangeira; só então sai o antigo
            $table->unique(['company_id', 'purpose']);
        });
        Schema::table('offer_schedules', function (Blueprint $table) {
            $table->dropUnique(['company_id']);
        });

        Schema::table('affiliate_offers', function (Blueprint $table) {
            // Endereço final do produto (depois dos redirecionamentos do link curto) e a "identidade" dele
            // (ASIN da Amazon, MLB do Mercado Livre, loja+item da Shopee): avisa quando o produto já foi cadastrado
            $table->string('source_url', 2000)->nullable()->after('url');
            $table->string('product_key', 191)->nullable()->after('source_url');
            // Descrição que a loja mostrou na prévia do link — só pra IA escrever o texto (não vai na mensagem)
            $table->text('description')->nullable()->after('offer_text');
            // Oferta relâmpago: "⚡ OFERTA RELÂMPAGO" no topo da mensagem
            $table->boolean('flash')->default(false)->after('expires_at');
            $table->index(['company_id', 'product_key']);
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_offers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'product_key']);
            $table->dropColumn(['source_url', 'product_key', 'description', 'flash']);
        });
        Schema::table('offer_schedules', function (Blueprint $table) {
            $table->unique('company_id');
        });
        Schema::table('offer_schedules', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'purpose']);
            $table->dropColumn('purpose');
        });
    }
};
