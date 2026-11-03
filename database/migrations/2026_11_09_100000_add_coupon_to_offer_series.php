<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_series', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('active')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offer_series', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
        });
    }
};
