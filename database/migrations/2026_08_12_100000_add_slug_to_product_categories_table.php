<?php

use App\Models\ProductCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * URL própria por categoria no catálogo (ex: /catalog/loja/chaveiros/carros).
 *
 * Mesmo princípio do slug de produto: gerado uma vez, nunca muda ao renomear —
 * sem isso, renomear uma categoria quebraria todo link de categoria já
 * compartilhado, do mesmo jeito que aconteceria com produto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        // Backfill: dedup dentro do mesmo grupo (empresa + pai; padrões do sistema
        // formam seu próprio grupo, já que company_id é nulo pra todas elas).
        $used = [];
        ProductCategory::orderBy('id')->get()->each(function ($category) use (&$used) {
            $scopeKey = ($category->company_id ?? 'default').':'.($category->parent_id ?? 'root');
            $base = Str::slug($category->name) ?: 'categoria';
            $slug = $base;
            $suffix = 2;
            while (in_array($slug, $used[$scopeKey] ?? [], true)) {
                $slug = "{$base}-{$suffix}";
                $suffix++;
            }
            $used[$scopeKey][] = $slug;
            $category->update(['slug' => $slug]);
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->unique(['company_id', 'parent_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'parent_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
