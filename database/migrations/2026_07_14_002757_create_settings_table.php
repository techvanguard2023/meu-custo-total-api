<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('electricity_rate_kwh', 10, 4)->default(0);
            $table->decimal('labor_hour_rate', 10, 2)->default(0);
            $table->decimal('default_failure_rate', 5, 2)->default(0);
            $table->decimal('default_markup', 6, 2)->default(0);
            $table->decimal('minimum_order_price', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
