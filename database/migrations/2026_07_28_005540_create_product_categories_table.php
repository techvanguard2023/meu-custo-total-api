<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Lista inicial — pra adicionar uma categoria nova depois, basta inserir uma
        // linha nesta tabela (não precisa de deploy nem migration).
        $now = now();
        DB::table('product_categories')->insert(collect([
            'Miniaturas',
            'Decoração',
            'Utilidades Domésticas',
            'Brinquedos',
            'Chaveiros',
            'Peças Técnicas/Funcionais',
            'Presentes/Personalizados',
            'Papelaria',
            'Outros',
        ])->map(fn ($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])->all());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
