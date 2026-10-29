<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Nicho da empresa (impressão 3D, artesanato, papelaria, confeitaria, outro): muda o vocabulário das
 * telas e quais categorias padrão aparecem. Quem já usa o sistema é de impressão 3D e continua igual.
 */
return new class extends Migration
{
    private const MATERIAL_DEFAULTS = [
        'crafts' => [
            ['Tecidos', 'unit'], ['Linhas e Fios', 'weight'], ['Aviamentos', 'unit'], ['MDF e Madeira', 'unit'], ['Tintas e Vernizes', 'unit'],
        ],
        'stationery' => [
            ['Papéis', 'unit'], ['Tinta e Toner', 'unit'], ['Adesivo e Vinil', 'unit'], ['Acabamentos', 'unit'],
        ],
        'bakery' => [
            ['Ingredientes', 'weight'], ['Chocolates', 'weight'], ['Laticínios', 'weight'], ['Confeitos e Decoração', 'weight'],
        ],
    ];

    private const PRODUCT_DEFAULTS = [
        'crafts' => ['Bijuterias', 'Bolsas e Acessórios', 'Crochê e Tricô', 'Lembrancinhas'],
        'stationery' => ['Cadernos e Agendas', 'Convites', 'Etiquetas e Adesivos', 'Kits Festa'],
        'bakery' => ['Bolos', 'Doces', 'Salgados', 'Tortas', 'Cestas e Kits'],
    ];

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('segment', 20)->default('3d_printing')->after('plan');
        });

        // Categoria padrão de um nicho só (nulo = serve pra todos)
        foreach (['material_categories', 'product_categories'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('segment', 20)->nullable()->after('company_id');
            });
        }

        DB::table('material_categories')->whereNull('company_id')
            ->whereIn('name', ['PLA', 'PETG', 'ABS', 'Flex (TPU)', 'Resina'])
            ->update(['segment' => '3d_printing']);
        DB::table('product_categories')->whereNull('company_id')
            ->whereIn('name', ['Miniaturas', 'Chaveiros', 'Peças Técnicas/Funcionais', 'Utilidades Domésticas', 'Brinquedos'])
            ->update(['segment' => '3d_printing']);

        $now = now();
        foreach (self::MATERIAL_DEFAULTS as $segment => $rows) {
            foreach ($rows as [$name, $unit]) {
                DB::table('material_categories')->insert([
                    'segment' => $segment, 'name' => $name, 'pricing_unit' => $unit, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        foreach (self::PRODUCT_DEFAULTS as $segment => $names) {
            foreach ($names as $name) {
                DB::table('product_categories')->insert([
                    'segment' => $segment, 'name' => $name, 'slug' => Str::slug($name), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['material_categories', 'product_categories'] as $name) {
            DB::table($name)->whereNull('company_id')->whereNotNull('segment')->where('segment', '!=', '3d_printing')->delete();
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('segment'));
        }

        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('segment'));
    }
};
