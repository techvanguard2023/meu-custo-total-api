<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Achadinhos (Pro): ofertas de produtos de outras lojas (Mercado Livre, Shopee, Amazon...) com o link
        // de afiliado da pessoa. Vão só para as séries do tipo "affiliate" — nunca pros grupos dos clientes da loja.
        Schema::table('offer_series', function (Blueprint $table) {
            $table->string('purpose', 10)->default('store')->after('kind'); // store | affiliate
            // Linha "Link de afiliado" no fim de cada achadinho (recomendado: publicidade identificada)
            $table->boolean('affiliate_disclosure')->default(true)->after('purpose');
        });

        Schema::create('affiliate_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('marketplace', 20); // mercadolivre | shopee | amazon | magalu | aliexpress | shein | other
            $table->string('url', 2000); // link de afiliado, como a pessoa colou
            $table->string('title', 200);
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('original_price', 10, 2)->nullable(); // "de" (riscado)
            $table->string('store_coupon', 60)->nullable(); // cupom da loja de origem (só texto)
            $table->text('offer_text')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'active']);
        });

        Schema::table('offer_posts', function (Blueprint $table) {
            $table->foreignId('affiliate_offer_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offer_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('affiliate_offer_id');
        });
        Schema::dropIfExists('affiliate_offers');
        Schema::table('offer_series', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'affiliate_disclosure']);
        });
    }
};
