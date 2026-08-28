<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categoria de material — mesmo padrão de categoria de produto (padrão do
 * sistema, `company_id` nulo, visível a todo mundo; criada pela empresa,
 * só ela enxerga), mas sem hierarquia (uma lista só, sem subcategoria).
 *
 * Diferente de produto, aqui a categoria também decide como o material é
 * precificado: por peso (grama) ou por unidade. Isso é travado na criação —
 * mudar depois bagunçaria retroativamente todo material já cadastrado nela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('pricing_unit', 10); // weight | unit
            $table->timestamps();
            $table->unique(['company_id', 'name'], 'material_categories_company_name_unique');
        });

        // Lista inicial — migra as 9 opções fixas que já existiam no campo "Tipo".
        $now = now();
        $defaults = collect([
            ['name' => 'PLA', 'pricing_unit' => 'weight'],
            ['name' => 'PETG', 'pricing_unit' => 'weight'],
            ['name' => 'ABS', 'pricing_unit' => 'weight'],
            ['name' => 'Flex (TPU)', 'pricing_unit' => 'weight'],
            ['name' => 'Resina', 'pricing_unit' => 'weight'],
            ['name' => 'Embalagem', 'pricing_unit' => 'unit'],
            ['name' => 'Componente', 'pricing_unit' => 'unit'],
            ['name' => 'Serviço', 'pricing_unit' => 'unit'],
            ['name' => 'Outro', 'pricing_unit' => 'unit'],
        ])->map(fn ($row) => array_merge($row, ['created_at' => $now, 'updated_at' => $now]));

        DB::table('material_categories')->insert($defaults->all());

        Schema::table('materials', function (Blueprint $table) {
            // O "Tipo" antigo fica no banco como histórico, mas para de ser usado —
            // a partir daqui a classificação (e a unidade de preço) vem da categoria.
            $table->foreignId('material_category_id')->nullable()->after('type')
                ->constrained()->nullOnDelete();
        });

        $categoryIdsByName = DB::table('material_categories')->whereNull('company_id')->pluck('id', 'name');
        foreach ($categoryIdsByName as $name => $id) {
            DB::table('materials')->where('type', $name)->update(['material_category_id' => $id]);
        }

        // Material sem "Tipo" reconhecido (ou em branco) cai em "Outro" — evita
        // deixar material antigo sem categoria nenhuma depois desta migração.
        $outroId = $categoryIdsByName['Outro'] ?? null;
        if ($outroId) {
            DB::table('materials')->whereNull('material_category_id')->update(['material_category_id' => $outroId]);
        }
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_category_id');
        });

        Schema::dropIfExists('material_categories');
    }
};
