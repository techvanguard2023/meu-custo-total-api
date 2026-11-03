<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cupom automático da loja (hoje: reativação de quem sumiu). Uma linha por tipo.
        Schema::create('coupon_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('inactive_days')->default(90);
            $table->string('discount_type', 10)->default('percent');
            $table->decimal('value', 10, 2)->default(10);
            $table->decimal('min_order', 10, 2)->nullable();
            $table->unsignedSmallInteger('validity_days')->default(15);
            $table->unsignedSmallInteger('daily_limit')->default(20);
            $table->boolean('allow_promotions')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'type']);
        });

        // Cupom pessoal (gerado pela automação): só o cliente dono (pelo telefone) usa, uma vez
        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('company_id')->constrained()->cascadeOnDelete();
            $table->string('automation', 30)->nullable()->after('customer_id');
            $table->timestamp('notified_at')->nullable()->after('active');
            $table->string('notify_error', 40)->nullable()->after('notified_at');
            $table->index(['company_id', 'automation']);
        });

        // Cliente que não quer receber cupons/ofertas automáticas no WhatsApp
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('promo_opt_out')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('promo_opt_out'));
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'automation']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['automation', 'notified_at', 'notify_error']);
        });
        Schema::dropIfExists('coupon_automations');
    }
};
