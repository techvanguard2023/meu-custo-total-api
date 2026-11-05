<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * De onde a loja veio quando se cadastrou (etiquetas utm_*, clique do Google Ads, site de origem) e o canal já
     * classificado (google_ads, instagram, organic_search, direct...). Lojas de antes da medição ficam com null.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('signup_attribution')->nullable()->after('pro_trial_notices');
            $table->string('signup_channel', 30)->nullable()->after('signup_attribution')->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['signup_channel']);
            $table->dropColumn(['signup_attribution', 'signup_channel']);
        });
    }
};
