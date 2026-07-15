<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('print_time_minutes');
            $table->decimal('material_weight_g', 10, 2);
            $table->unsignedInteger('setup_minutes')->default(0);
            $table->unsignedInteger('postprocess_minutes')->default(0);
            $table->decimal('extra_costs', 10, 2)->default(0);
            $table->decimal('failure_rate_percent', 5, 2)->default(0);
            $table->decimal('markup_percent', 6, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);

            // snapshot dos valores calculados no momento do orçamento
            $table->decimal('material_cost', 10, 2)->default(0);
            $table->decimal('energy_cost', 10, 2)->default(0);
            $table->decimal('depreciation_cost', 10, 2)->default(0);
            $table->decimal('labor_cost', 10, 2)->default(0);
            $table->decimal('failure_cost', 10, 2)->default(0);
            $table->decimal('subtotal_cost', 10, 2)->default(0);
            $table->decimal('final_price', 10, 2)->default(0);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('profit_amount', 10, 2)->default(0);

            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
