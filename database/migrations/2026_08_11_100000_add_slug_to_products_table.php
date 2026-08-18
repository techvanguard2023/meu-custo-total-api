<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * URL própria por produto no catálogo (ex: /catalog/loja/chaveiros/carros/gol-g5-2012).
 *
 * O slug é gerado uma vez, na criação, e nunca muda sozinho — se mudasse junto
 * com o nome, todo link já compartilhado (WhatsApp, Instagram) quebraria. Único
 * por empresa, não globalmente: duas lojas podem ter cada uma o seu "vaso-azul".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        // Backfill: produtos já cadastrados também precisam de slug pra que os
        // links do catálogo funcionem imediatamente após o deploy, sem esperar
        // uma edição do lojista.
        Product::query()
            ->orderBy('company_id')
            ->orderBy('id')
            ->chunkById(200, function ($products) {
                foreach ($products as $product) {
                    $product->update(['slug' => $this->uniqueSlug($product)]);
                }
            });

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['company_id', 'slug']);
        });
    }

    private function uniqueSlug(Product $product): string
    {
        $base = Str::slug($product->name) ?: 'produto';
        $slug = $base;
        $suffix = 2;

        while (
            Product::where('company_id', $product->company_id)
                ->where('slug', $slug)
                ->where('id', '!=', $product->id)
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
