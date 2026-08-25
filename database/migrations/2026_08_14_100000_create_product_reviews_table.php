<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avaliação de produto (ou da loja) pelo cliente que comprou.
 *
 * Toda avaliação nasce de uma venda real (quote_id): o cliente só chega aqui
 * pelo link que o lojista envia depois da entrega, então não existe avaliação
 * de quem não comprou — é verificada por construção.
 *
 * PRODUCT_ID é nulo quando a venda não tem produto de catálogo vinculado (caso
 * comum: peça sob encomenda, feita sob medida) — nesse caso a avaliação é da
 * loja como um todo, não de um produto específico, e aparece como depoimento
 * no catálogo em vez de no card de um produto.
 *
 * A NOTA entra na média assim que enviada; só o COMENTÁRIO passa por moderação,
 * porque é o texto que vai aparecer no catálogo público do lojista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            // Gerado sob demanda, ao pedir a avaliação — não em toda venda.
            $table->string('review_token', 40)->nullable()->unique()->after('paid_at');
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            // pending = escrito, aguardando o lojista liberar; approved = visível
            // no catálogo; rejected = fica guardado, mas nunca aparece.
            $table->string('comment_status', 20)->default('pending');
            // Copiado do cliente da venda no momento do envio — a exibição usa só
            // o primeiro nome, e a avaliação sobrevive se o cadastro mudar depois.
            $table->string('reviewer_name')->nullable();
            $table->timestamps();

            // Uma avaliação por produto em cada venda; reenviar o link atualiza a
            // que já existe em vez de duplicar.
            $table->unique(['quote_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropUnique(['review_token']);
            $table->dropColumn('review_token');
        });
    }
};
