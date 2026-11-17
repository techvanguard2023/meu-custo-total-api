<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Quote;
use App\Services\ProductionStageNotifier;
use Tests\TestCase;

/** Avisos de etapa no WhatsApp: padrão em linhas separadas, e a cópia salva do padrão antigo passa a usar o novo. */
class StatusMessageFormatTest extends TestCase
{
    public function test_delivered_default_is_split_in_lines(): void
    {
        $message = ProductionStageNotifier::settingsFor(new Company(['name' => 'Loja']))[Quote::PRODUCTION_DELIVERED]['message'];

        $this->assertSame("Olá, {cliente}!\nSeu pedido *{pedido}* foi entregue.\n\nObrigado por comprar na {loja}! Qualquer dúvida é só chamar.", $message);
    }

    public function test_items_on_their_own_line_become_a_bullet_list(): void
    {
        $quote = new class extends Quote
        {
            public function soldLines(): array
            {
                return [
                    ['quantity' => 2, 'description' => 'Árvore de Natal de palavras'],
                    ['quantity' => 1, 'description' => 'Mini Enfeite de Anjo de Natal'],
                ];
            }
        };
        $quote->id = 184;
        $quote->setRelation('customer', new \App\Models\Customer(['name' => 'Michelle Souza']));
        $company = new Company(['name' => 'Alma & Forma 3D']);
        $render = fn (string $template) => (new \ReflectionMethod(ProductionStageNotifier::class, 'render'))
            ->invoke(app(ProductionStageNotifier::class), $template, $quote, $company);

        $this->assertSame(
            "Olá, Michelle!\nSeu pedido *#184* está pronto:\n• 2x Árvore de Natal de palavras\n• 1x Mini Enfeite de Anjo de Natal\n\nVamos combinar a entrega ou retirada.",
            $render(ProductionStageNotifier::settingsFor($company)[Quote::PRODUCTION_FINISHED]['message'])
        );
        // Texto do lojista com {itens} no meio da frase segue com vírgulas
        $this->assertSame('Pedido #184 (2x Árvore de Natal de palavras, 1x Mini Enfeite de Anjo de Natal)', $render('Pedido {pedido} ({itens})'));
        // Lembrete de pagamento: um item por linha
        $this->assertSame("• 2x Árvore de Natal de palavras\n• 1x Mini Enfeite de Anjo de Natal", \App\Services\PaymentReminder::itemsBullets($quote));
    }

    public function test_saved_copy_of_the_old_default_uses_the_new_one(): void
    {
        $company = new Company(['name' => 'Loja', 'whatsapp_status_notifications' => [
            Quote::PRODUCTION_DELIVERED => ['enabled' => true, 'message' => 'Olá, {cliente}! Seu pedido *{pedido}* foi entregue. Obrigado por comprar na {loja}! Qualquer dúvida é só chamar.'],
            Quote::PRODUCTION_FINISHED => ['enabled' => true, 'message' => 'Oi {cliente}, ficou pronto!'],
        ]]);
        $settings = ProductionStageNotifier::settingsFor($company);

        $this->assertStringStartsWith("Olá, {cliente}!\nSeu pedido", $settings[Quote::PRODUCTION_DELIVERED]['message']);
        // Texto escrito pelo lojista continua o dele
        $this->assertSame('Oi {cliente}, ficou pronto!', $settings[Quote::PRODUCTION_FINISHED]['message']);
    }
}
