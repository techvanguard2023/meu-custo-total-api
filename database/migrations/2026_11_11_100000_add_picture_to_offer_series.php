<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foto dos grupos de ofertas: padrão de todos (atuais e os próximos #N) ou só do grupo atual
        Schema::table('offer_series', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('description');
            $table->boolean('image_all_groups')->default(true)->after('image_path');
        });

        Schema::table('offer_groups', function (Blueprint $table) {
            $table->boolean('has_picture')->default(false)->after('backup_admin_added');
        });
    }

    public function down(): void
    {
        Schema::table('offer_groups', fn (Blueprint $table) => $table->dropColumn('has_picture'));
        Schema::table('offer_series', fn (Blueprint $table) => $table->dropColumn(['image_path', 'image_all_groups']));
    }
};
