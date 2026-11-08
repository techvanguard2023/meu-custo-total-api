<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagamento online (Fase 1):
 *  - quote_payments: registro de cada pagamento de uma venda (o `quotes.amount_paid` vira a soma dele). Os
 *    pedidos que já tinham valor recebido ganham uma linha com esse saldo.
 *  - payment_accounts: a conta do Mercado Pago que a loja conectou (tokens criptografados).
 *  - payment_charges: cada Pix gerado pelo sistema (pedido no Mercado Pago, QR, validade, situação).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            // Positivo = entrou dinheiro; negativo = correção para menos, cortesia ou estorno
            $table->decimal('amount', 10, 2);
            $table->string('method', 20)->nullable();
            // manual | mercadopago | display | import (saldo anterior ao registro)
            $table->string('source', 20)->default('manual');
            // Id do pagamento no provedor — o mesmo aviso nunca entra duas vezes
            $table->string('external_id', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'paid_at']);
            $table->unique(['company_id', 'source', 'external_id']);
        });

        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('mercadopago');
            $table->string('provider_user_id', 40)->index();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('public_key', 120)->nullable();
            $table->boolean('live_mode')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            // Desconectada pelo lojista no Mercado Pago (ou token recusado): precisa conectar de novo
            $table->timestamp('revoked_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('payment_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('mercadopago');
            $table->string('method', 20)->default('pix');
            $table->string('external_id', 64)->nullable()->index(); // ORD... (pedido no Mercado Pago)
            $table->string('external_payment_id', 64)->nullable();  // PAY...
            $table->decimal('amount', 10, 2);
            // pending | paid | expired | canceled | failed | refunded
            $table->string('status', 20)->default('pending');
            $table->string('provider_status', 40)->nullable();
            $table->text('qr_code')->nullable();
            $table->string('ticket_url', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['quote_id', 'status']);
        });

        // Saldo já recebido vira a primeira linha do registro de cada venda
        DB::table('quotes')->where('amount_paid', '>', 0)->orderBy('id')->chunkById(500, function ($quotes) {
            DB::table('quote_payments')->insert($quotes->map(fn ($q) => [
                'company_id' => $q->company_id,
                'quote_id' => $q->id,
                'amount' => $q->amount_paid,
                'method' => $q->payment_method,
                'source' => 'import',
                'note' => 'Recebido antes do registro de pagamentos',
                'paid_at' => $q->paid_at ?? $q->approved_at ?? $q->updated_at,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_charges');
        Schema::dropIfExists('payment_accounts');
        Schema::dropIfExists('quote_payments');
    }
};
