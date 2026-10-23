<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pra desenhar a imagem do aviso na hora de enviar ("Você ganhou R$ X de cashback")
        Schema::table('cashback_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('cashback_notifications', 'amount')) {
                $table->decimal('amount', 10, 2)->nullable()->after('message');
            }
            if (! Schema::hasColumn('cashback_notifications', 'valid_until')) {
                $table->timestamp('valid_until')->nullable()->after('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cashback_notifications', function (Blueprint $table) {
            $table->dropColumn(['amount', 'valid_until']);
        });
    }
};
