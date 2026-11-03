<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cupom divulgado nos grupos/canais de ofertas ("Divulgar agora")
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('share_in_offers')->default(false)->after('allow_promotions');
            $table->timestamp('broadcast_at')->nullable()->after('share_in_offers');
        });

        // Post de oferta que é de um cupom (sem produto)
        Schema::table('offer_posts', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offer_posts', fn (Blueprint $table) => $table->dropConstrainedForeignId('coupon_id'));
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn(['share_in_offers', 'broadcast_at']));
    }
};
