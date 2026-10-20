<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ficha técnica: quanto de cada insumo vai em uma unidade do produto. Linhas sem
        // variação valem pra todas; as de uma variação somam às comuns (ex: o filamento da cor).
        Schema::create('product_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('auto_cost')->default(false)->after('cost');
            $table->unsignedInteger('print_time_minutes')->nullable()->after('auto_cost');
            $table->unsignedInteger('postprocess_minutes')->nullable()->after('print_time_minutes');
            $table->foreignId('printer_id')->nullable()->after('postprocess_minutes')->constrained()->nullOnDelete();
        });

        Schema::table('product_variations', function (Blueprint $table) {
            $table->unsignedInteger('print_time_minutes')->nullable()->after('cost');
        });

        // Na venda: se a unidade saiu do estoque pronto (os insumos já foram baixados quando ela foi
        // produzida) ou se ainda vai ser produzida — aí os insumos baixam quando o pedido entra em produção.
        Schema::table('quote_items', function (Blueprint $table) {
            $table->boolean('stock_deducted')->nullable()->after('amount');
            $table->timestamp('materials_consumed_at')->nullable()->after('stock_deducted');
        });

        Schema::create('material_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->decimal('quantity', 12, 2); // positivo = entrada, negativo = saída
            $table->decimal('balance_after', 12, 2)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['material_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_movements');

        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn(['stock_deducted', 'materials_consumed_at']);
        });

        Schema::table('product_variations', function (Blueprint $table) {
            $table->dropColumn('print_time_minutes');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('printer_id');
            $table->dropColumn(['auto_cost', 'print_time_minutes', 'postprocess_minutes']);
        });

        Schema::dropIfExists('product_materials');
    }
};
