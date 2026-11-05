<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Exclusão agendada pela área admin: a loja não consegue desfazer sozinha (só o admin, na ficha). */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('deletion_by_admin')->default(false)->after('deletion_paused_subscription');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('deletion_by_admin');
        });
    }
};
