<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Papel da categoria de material na ficha técnica do produto:
 *  - production: o que vira o produto (filamento, resina, tecido, ingredientes) — entra na taxa de falha;
 *  - finishing: acabamento e embalagem (caixa, sacola, tag, argola, tinta) — só vai na peça boa, sem taxa de falha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_categories', function (Blueprint $table) {
            $table->string('role', 20)->default('production')->after('pricing_unit');
        });

        // Padrão do sistema: embalagem e componentes (argola, ímã...) de todos os nichos e os acabamentos da papelaria
        DB::table('material_categories')->whereNull('company_id')
            ->where(fn ($q) => $q->whereIn('name', ['Embalagem', 'Componente'])->orWhere(fn ($s) => $s->where('segment', 'stationery')->where('name', 'Acabamentos')))
            ->update(['role' => 'finishing']);

        // Categoria criada pela loja com nome de embalagem/acabamento já entra certa (dá pra trocar na tela)
        DB::table('material_categories')->whereNotNull('company_id')
            ->where(fn ($q) => $q->where('name', 'like', '%embala%')->orWhere('name', 'like', '%sacol%')->orWhere('name', 'like', '%caixa%'))
            ->update(['role' => 'finishing']);

        // Pintura da peça impressa (o print citou tinta): categoria padrão nova do 3D, por peso
        $exists = DB::table('material_categories')->whereNull('company_id')->where('name', 'Tintas e Acabamento')->exists();
        if (! $exists) {
            DB::table('material_categories')->insert([
                'company_id' => null, 'segment' => '3d_printing', 'name' => 'Tintas e Acabamento', 'pricing_unit' => 'weight',
                'role' => 'finishing', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('material_categories')->whereNull('company_id')->where('name', 'Tintas e Acabamento')
            ->whereNotExists(fn ($q) => $q->from('materials')->whereColumn('materials.material_category_id', 'material_categories.id'))
            ->delete();

        Schema::table('material_categories', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
