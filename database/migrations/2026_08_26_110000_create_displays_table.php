<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expositor: estoque de produtos colocado em consignação numa loja parceira.
 * O lojista repõe produtos (sai do estoque principal), depois confere de
 * tempos em tempos quanto sobrou — a diferença vira venda automaticamente,
 * com a comissão do parceiro já deduzida do lucro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('displays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->decimal('commission_percent', 5, 2)->default(0);
            // testing = em teste, active, paused, ended
            $table->string('status', 20)->default('testing');
            $table->date('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Quanto de cada produto está fisicamente no expositor agora — é a
        // fonte da verdade consultada (e atualizada) a cada reposição/conferência.
        Schema::create('display_stock_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('display_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity_current')->default(0);
            $table->timestamps();
            $table->unique(['display_id', 'product_id', 'product_variation_id'], 'display_stock_lines_unique');
        });

        // Histórico: cada reposição, venda apurada, perda registrada e devolução ao
        // encerrar o expositor vira uma linha aqui — é o extrato do expositor.
        Schema::create('display_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('display_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20); // restock|sale|loss|return
            $table->unsignedInteger('quantity');
            // Preenchido só nas linhas do tipo "sale" — liga o movimento à venda gerada.
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('display_id')->nullable()->after('sales_channel_id')
                ->constrained()->nullOnDelete();
            // Guardado no momento da conferência: a comissão paga ao parceiro não
            // pode mudar retroativamente se a % do expositor for editada depois.
            $table->decimal('display_commission_amount', 10, 2)->default(0)->after('channel_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('display_id');
            $table->dropColumn('display_commission_amount');
        });

        Schema::dropIfExists('display_stock_movements');
        Schema::dropIfExists('display_stock_lines');
        Schema::dropIfExists('displays');
    }
};
