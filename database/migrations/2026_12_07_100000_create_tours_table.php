<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tours do app (react-joyride) cadastrados na área admin — antes ficavam no código (config/tours.php e
 * src/lib/tours.ts) e cada mudança pedia deploy. O slug é o id que as marcações de "visto" (user_tours) usam.
 * Já entram os tours que existiam (mesmos slugs e versões: quem já viu não vê de novo) e o de funcionários e impostos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tours', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('title', 120);
            $table->string('plan', 12)->default('free'); // free | essential | pro: plano mínimo da feature
            $table->unsignedInteger('version')->default(1); // subir = quem já viu vê de novo
            $table->string('route', 200); // tela onde o tour completo roda
            $table->json('steps'); // [{target, title, content, placement?}]
            $table->string('preview_title', 120); // prévia "Ver planos" no Dashboard, pra quem não tem o plano
            $table->text('preview_content');
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $tours = [
            [
                'slug' => 'caixa', 'title' => 'Caixa (venda rápida)', 'plan' => 'essential', 'route' => '/cashier',
                'steps' => [
                    ['target' => '#cashier-search', 'title' => 'Busque o produto', 'content' => 'Digite o nome ou o código do produto para colocar no carrinho.'],
                    ['target' => '#cashier-customer', 'title' => 'Cliente da venda', 'content' => 'Escolha o cliente para a venda ficar no nome dele. Se ele tiver cashback, o saldo aparece aqui.'],
                    ['target' => '#cashier-discount-value', 'title' => 'Desconto', 'content' => 'Se quiser, dê um desconto em porcentagem ou em valor antes de fechar.'],
                    ['target' => '#cashier-finalize', 'title' => 'Finalize a venda', 'content' => 'Confira o total e finalize. A venda fica registrada na hora.'],
                ],
                'preview_title' => 'Caixa (venda rápida)', 'preview_content' => 'Registre vendas de balcão em segundos: busque o produto, escolha o cliente e finalize.',
            ],
            [
                'slug' => 'agenda', 'title' => 'Agenda de entregas', 'plan' => 'pro', 'route' => '/agenda',
                'steps' => [
                    ['target' => '#agenda-today', 'title' => 'Agenda de entregas', 'content' => 'Aqui ficam as entregas combinadas nos pedidos. Use os botões para trocar de período e volte para hoje com um clique.'],
                    ['target' => '#agenda-week, #agenda-month, #agenda-list', 'title' => 'Entregas por dia', 'content' => 'Cada pedido aparece no dia combinado. Clique em um pedido para ver os detalhes.'],
                    ['target' => '#agenda-overdue', 'title' => 'Atrasadas', 'content' => 'Entregas com a data já passada ficam aqui em destaque, para não esquecer nenhuma.'],
                ],
                'preview_title' => 'Agenda de entregas', 'preview_content' => 'Veja as entregas combinadas nos pedidos, dia a dia, e as atrasadas em destaque.',
            ],
            [
                'slug' => 'sino-entregas', 'title' => 'Avisos de entrega', 'plan' => 'pro', 'route' => '/',
                'steps' => [
                    ['target' => '#delivery-bell-desktop, #delivery-bell-mobile', 'title' => 'Avisos de entrega', 'content' => 'O sino mostra as entregas de hoje e de amanhã. O número indica quantas ainda estão pendentes.'],
                ],
                'preview_title' => 'Avisos de entrega', 'preview_content' => 'Um sino no topo da tela avisa as entregas de hoje e de amanhã.',
            ],
            [
                'slug' => 'cashback', 'title' => 'Cashback', 'plan' => 'pro', 'route' => '/settings#clientes',
                'steps' => [
                    ['target' => '#cashback-settings-card', 'title' => 'Cashback para clientes', 'content' => 'Seus clientes ganham parte do valor de volta para usar nas próximas compras.'],
                    ['target' => '#cashback-enabled-toggle', 'title' => 'Ligue o programa', 'content' => 'Ligue aqui para começar. Depois é só ajustar as regras abaixo e salvar.'],
                    ['target' => '#cashback-purchase', 'title' => 'Quanto volta', 'content' => 'A porcentagem de cada compra que vira saldo de cashback para o cliente.'],
                    ['target' => '#cashback-max', 'title' => 'Limite de uso', 'content' => 'Até quanto do valor de um pedido o cliente pode pagar com o saldo.'],
                    ['target' => '#cashback-validity', 'title' => 'Validade', 'content' => 'Por quanto tempo o saldo vale antes de vencer.'],
                ],
                'preview_title' => 'Cashback', 'preview_content' => 'Seus clientes ganham parte do valor de volta para usar na próxima compra, e voltam a comprar com você.',
            ],
            [
                'slug' => 'pagamento-online', 'title' => 'Pagamento online', 'plan' => 'pro', 'route' => '/integrations',
                'steps' => [
                    ['target' => '#mercadopago', 'title' => 'Pagamento online', 'content' => 'Conecte sua conta do Mercado Pago para gerar Pix e link de cartão nas vendas. O dinheiro cai direto na sua conta.'],
                    ['target' => '#mercadopago-connect', 'title' => 'Conecte sua conta', 'content' => 'Clique aqui e autorize no Mercado Pago. Leva menos de um minuto.'],
                    ['target' => '#mercadopago-bot-rules', 'title' => 'Pix no pedido do WhatsApp', 'content' => 'Escolha se o atendente manda o Pix do valor total, de um sinal, ou não manda.'],
                ],
                'preview_title' => 'Pagamento online', 'preview_content' => 'Gere Pix e link de cartão nas vendas. Quando o cliente paga, a venda dá baixa sozinha.',
            ],
            [
                'slug' => 'funcionarios-impostos', 'title' => 'Funcionários e impostos', 'plan' => 'essential', 'route' => '/settings#precos',
                'steps' => [
                    ['target' => '#fixed-costs', 'title' => 'Custos fixos do mês', 'content' => 'Novidade: agora a folha de pagamento, as despesas fixas da loja e os impostos podem entrar no preço dos seus orçamentos e produtos.'],
                    ['target' => '#employees-list', 'title' => 'Cadastre quem trabalha na loja', 'content' => 'Nome ou função, salário, encargos e horas por mês. O seu pró-labore pode ser uma linha. A folha do mês é somada sozinha.'],
                    ['target' => '#fixed-expenses-list', 'title' => 'Despesas fixas da loja', 'content' => 'Aluguel, água, luz do espaço, internet, contador: as contas que você paga todo mês, vendendo ou não. A energia das impressoras já entra em cada peça.'],
                    ['target' => '#tax-percent', 'title' => 'Imposto sobre a venda', 'content' => 'A alíquota do Simples Nacional, por exemplo. Ela entra "por dentro" no preço: o imposto sai do preço e o seu lucro não diminui.'],
                    ['target' => '#tax-fixed-monthly', 'title' => 'Imposto fixo por mês', 'content' => 'O DAS do MEI, por exemplo. Esse valor é dividido junto com a folha e as despesas.'],
                    ['target' => '#fixed-costs-modes', 'title' => 'Como os custos fixos entram no preço', 'content' => 'Por % do faturamento, pela hora de trabalho ou por pedido. Cada opção mostra um exemplo com os seus números. Em "Não incluir", nada muda nos seus preços.'],
                    ['target' => '#save-settings', 'title' => 'Salve e confira', 'content' => 'Antes de salvar, mostramos um produto com o preço de antes e de depois, para você confirmar.'],
                ],
                'preview_title' => 'Funcionários, despesas e impostos no preço', 'preview_content' => 'Coloque a folha de pagamento, as despesas fixas (aluguel, água, internet) e os impostos (Simples ou MEI) no preço dos orçamentos e produtos, sem perder margem.',
            ],
        ];

        foreach ($tours as $i => $tour) {
            DB::table('tours')->insert(array_merge($tour, [
                'steps' => json_encode($tour['steps'], JSON_UNESCAPED_UNICODE),
                'version' => 1,
                'active' => true,
                'sort_order' => ($i + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tours');
    }
};
