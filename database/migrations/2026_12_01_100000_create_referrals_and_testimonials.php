<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 da opinião dos lojistas:
 *  - indicação: cada loja tem um código (link www…/?ref=CODIGO); a loja indicada guarda quem indicou e, quando faz o
 *    primeiro pagamento, a que indicou ganha 1 mês de Pro (referral_rewarded_at/referral_reward na indicada);
 *  - depoimentos: texto do lojista com autorização pra publicar; o admin aprova e a landing mostra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->unique();
            $table->foreignId('referred_by_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->timestamp('referral_rewarded_at')->nullable();
            $table->json('referral_reward')->nullable();
        });

        Schema::create('platform_testimonials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete(); // um por loja
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('text');
            $table->string('author_name', 80);
            $table->string('status', 12)->default('pending'); // pending | approved | hidden
            $table->timestamp('consent_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_testimonials');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['referred_by_company_id']);
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referred_by_company_id', 'referral_rewarded_at', 'referral_reward']);
        });
    }
};
