<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR Code de Pix (destino "pix"): o QR leva o "Pix copia e cola" da loja (chave, nome, cidade e, se quiser,
 * valor, descrição e identificador). É fixo: não passa pelo link curto, então não muda depois nem conta leitura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            // {key_type, key, name, city, amount, description, txid}
            $table->json('pix')->nullable()->after('whatsapp_message');
        });
    }

    public function down(): void
    {
        Schema::table('qr_codes', fn (Blueprint $table) => $table->dropColumn('pix'));
    }
};
