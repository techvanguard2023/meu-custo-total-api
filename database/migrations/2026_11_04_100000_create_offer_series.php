<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Série de divulgação de ofertas (Pro): grupos "Nome #1, #2..." que abrem sozinhos ao lotar,
        // ou um canal do WhatsApp. O link público fixo (/ofertas/{slug}) leva sempre ao grupo com vaga.
        Schema::create('offer_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // group | channel
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->string('slug', 100)->unique();
            $table->unsignedSmallInteger('capacity')->default(900);
            // Segundo administrador dos grupos (opcional): mantém o controle se o número da loja cair
            $table->string('backup_admin_phone', 30)->nullable();
            // Canal: id (…@newsletter) e link de quem segue
            $table->string('channel_jid', 100)->nullable();
            $table->string('channel_invite', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('offer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_series_id')->constrained('offer_series')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('jid', 100);
            $table->string('invite_url', 255)->nullable();
            $table->unsignedSmallInteger('participants_count')->default(1);
            $table->timestamp('participants_checked_at')->nullable();
            $table->timestamp('full_at')->nullable();
            // Segundo admin colocado no grupo (false = não informado ou não deu certo)
            $table->boolean('backup_admin_added')->default(false);
            $table->timestamps();
            $table->unique(['offer_series_id', 'number']);
        });

        // "Enviar agora" e, depois, a agenda: cada oferta vira uma entrega por grupo/canal, enviadas
        // uma por minuto (offers:send) pra não disparar tudo de uma vez.
        Schema::create('offer_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 255);
            $table->string('origin', 20)->default('manual'); // manual | schedule
            $table->timestamps();
        });

        Schema::create('offer_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_series_id')->nullable()->constrained('offer_series')->nullOnDelete();
            $table->foreignId('offer_group_id')->nullable()->constrained('offer_groups')->nullOnDelete();
            $table->string('target_label', 120);
            $table->string('target_jid', 100);
            $table->string('status', 10)->default('pending'); // pending | sent | failed
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::table('products', function (Blueprint $table) {
            // Entra na divulgação das ofertas (grupos/canal)
            $table->boolean('share_in_offers')->default(false);
            $table->timestamp('offers_last_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['share_in_offers', 'offers_last_sent_at']);
        });
        Schema::dropIfExists('offer_deliveries');
        Schema::dropIfExists('offer_posts');
        Schema::dropIfExists('offer_groups');
        Schema::dropIfExists('offer_series');
    }
};
