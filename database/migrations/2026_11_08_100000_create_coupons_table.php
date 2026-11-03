<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('description')->nullable();
            $table->string('type', 10); // percent | fixed
            $table->decimal('value', 10, 2);
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->decimal('min_order', 10, 2)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedSmallInteger('per_customer_limit')->nullable();
            $table->boolean('first_purchase_only')->default(false);
            $table->boolean('allow_promotions')->default(false);
            $table->json('category_ids')->nullable();
            $table->json('product_ids')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('cashback_amount')->constrained()->nullOnDelete();
            $table->string('coupon_code', 30)->nullable()->after('coupon_id');
            $table->decimal('coupon_amount', 10, 2)->default(0)->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'coupon_amount']);
        });

        Schema::dropIfExists('coupons');
    }
};
