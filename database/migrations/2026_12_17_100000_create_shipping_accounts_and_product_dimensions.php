<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frete pelo Melhor Envio:
 *  - shipping_accounts: a conta do Melhor Envio que a loja conectou (tokens criptografados);
 *  - products: peso e medidas da embalagem (vazio = padrão da loja);
 *  - settings: CEP de origem, medidas padrão e prazo de preparo somado ao prazo da transportadora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('melhorenvio');
            $table->string('provider_user_id', 40)->nullable()->index();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('ship_weight_g')->nullable()->after('min_quantity');
            $table->unsignedSmallInteger('ship_length_cm')->nullable()->after('ship_weight_g');
            $table->unsignedSmallInteger('ship_width_cm')->nullable()->after('ship_length_cm');
            $table->unsignedSmallInteger('ship_height_cm')->nullable()->after('ship_width_cm');
        });

        Schema::table('settings', function (Blueprint $table) {
            // { origin_cep, weight_g, length_cm, width_cm, height_cm, handling_days, enabled }
            $table->json('shipping_rules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('shipping_rules'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['ship_weight_g', 'ship_length_cm', 'ship_width_cm', 'ship_height_cm']));
        Schema::dropIfExists('shipping_accounts');
    }
};
