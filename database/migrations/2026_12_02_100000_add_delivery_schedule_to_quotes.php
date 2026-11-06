<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda (Fase 1): data de entrega marcada no pedido, hora opcional, forma de entrega (retirada, entrega própria ou
 * envio) e endereço. O prazo em dias (delivery_days) continua existindo para orçamentos ainda não aprovados: na
 * aprovação ele vira data. Os pedidos já aprovados com prazo em dias ganham a data aqui (aprovação + dias, que já
 * contam as pausas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->date('delivery_date')->nullable()->after('delivery_days')->index();
            $table->string('delivery_time', 5)->nullable()->after('delivery_date'); // HH:MM
            $table->string('delivery_method', 10)->nullable()->after('delivery_time'); // pickup | local | shipping
            $table->string('delivery_address', 500)->nullable()->after('delivery_method');
        });

        DB::table('quotes')->whereNotNull('approved_at')->whereNotNull('delivery_days')->whereNull('delivery_date')
            ->orderBy('id')->select(['id', 'approved_at', 'delivery_days'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $date = Carbon::parse($row->approved_at, 'UTC')->timezone('America/Sao_Paulo')->startOfDay()->addDays((int) $row->delivery_days);
                    DB::table('quotes')->where('id', $row->id)->update(['delivery_date' => $date->toDateString()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['delivery_date']);
            $table->dropColumn(['delivery_date', 'delivery_time', 'delivery_method', 'delivery_address']);
        });
    }
};
