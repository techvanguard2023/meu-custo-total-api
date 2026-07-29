<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('catalog_instagram_url')->nullable()->after('catalog_disclaimer');
            $table->string('catalog_facebook_url')->nullable()->after('catalog_instagram_url');
            $table->string('catalog_youtube_url')->nullable()->after('catalog_facebook_url');
            $table->string('catalog_tiktok_url')->nullable()->after('catalog_youtube_url');
            $table->string('catalog_linkedin_url')->nullable()->after('catalog_tiktok_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'catalog_instagram_url',
                'catalog_facebook_url',
                'catalog_youtube_url',
                'catalog_tiktok_url',
                'catalog_linkedin_url',
            ]);
        });
    }
};
