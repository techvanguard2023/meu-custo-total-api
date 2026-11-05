<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foto do produto em WebP (app e catálogo) + cópia JPEG leve pra prévia de link e envio no WhatsApp
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('share_path')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', fn (Blueprint $table) => $table->dropColumn('share_path'));
    }
};
