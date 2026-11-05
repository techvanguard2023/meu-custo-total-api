<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Mensagens do teste grátis já tratadas: {"welcome": "2026-11-05T…", "day3": "skipped:…", …} — cada uma sai uma vez só. */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('pro_trial_notices')->nullable()->after('pro_trial_source');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('pro_trial_notices');
        });
    }
};
