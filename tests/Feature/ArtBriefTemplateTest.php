<?php

namespace Tests\Feature;

use App\Models\ArtBrief;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Briefing por produto: modelos de perguntas da loja, ligados aos produtos, e o link que o bot manda após o pedido. */
class ArtBriefTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Papelaria', 'slug' => 'papelaria-modelos', 'plan' => Company::PLAN_PRO, 'segment' => 'stationery']);
        $this->user = User::factory()->create(['company_id' => $company->id]);
        $this->token = $this->user->createToken('api')->plainTextToken;
    }

    private function api(?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token);
    }

    private function template(string $name, array $questions): int
    {
        return collect($this->api()->postJson('/api/v1/art-brief/templates', ['name' => $name, 'questions' => $questions])->assertOk()->json('templates'))
            ->firstWhere('name', $name)['id'];
    }

    private function product(string $name, array $extra = []): int
    {
        return $this->api()->postJson('/api/v1/products', $extra + ['name' => $name, 'cost' => 5, 'sale_price' => 20, 'stock_quantity' => 50])->assertCreated()->json('id');
    }

    private function botOrder(array $productIds, string $reference)
    {
        return $this->api($this->user->createToken('whatsapp-bot')->plainTextToken)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Kelly', 'customer_phone' => '5521988887777', 'external_reference' => $reference,
            'products' => array_map(fn ($id) => ['product_id' => $id, 'quantity' => 1], $productIds),
        ]);
    }

    public function test_templates_can_be_created_edited_and_deleted(): void
    {
        $id = $this->template('Topo de bolo', [['label' => 'Nome no topo', 'type' => 'text', 'required' => true]]);

        $this->api()->putJson("/api/v1/art-brief/templates/{$id}", ['name' => 'Topo de bolo 3D', 'questions' => [['label' => 'Nome', 'type' => 'text'], ['label' => 'Idade', 'type' => 'text']]])
            ->assertOk()->assertJsonPath('templates.0.name', 'Topo de bolo 3D')->assertJsonCount(2, 'templates.0.questions');

        $productId = $this->product('Topo', ['art_brief_enabled' => true, 'art_brief_template_id' => $id]);
        $this->api()->deleteJson("/api/v1/art-brief/templates/{$id}")->assertOk()->assertJsonCount(0, 'templates');
        // Produto ligado ao modelo apagado continua pedindo briefing, com as perguntas padrão
        $this->assertDatabaseHas('products', ['id' => $productId, 'art_brief_enabled' => true, 'art_brief_template_id' => null]);
    }

    public function test_bot_order_gets_the_brief_link_only_for_products_that_ask(): void
    {
        $topo = $this->template('Topo de bolo', [['key' => 'nome_topo', 'label' => 'Nome no topo', 'type' => 'text']]);
        $comBriefing = $this->product('Topo de bolo', ['art_brief_enabled' => true, 'art_brief_template_id' => $topo]);
        $semBriefing = $this->product('Chaveiro pronto');

        $this->botOrder([$semBriefing], 'msg-1')->assertCreated()->assertJsonMissingPath('0.art_brief_url');

        $url = $this->botOrder([$comBriefing, $semBriefing], 'msg-2')->assertCreated()->json('0.art_brief_url');
        $this->assertStringContainsString('/briefing/', $url);
        $this->assertSame(['nome_topo'], collect(ArtBrief::first()->questions)->pluck('key')->all());

        // Reenvio do mesmo pedido devolve o mesmo link
        $this->assertSame($url, $this->botOrder([$comBriefing], 'msg-2')->json('0.art_brief_url'));
    }

    public function test_products_with_different_templates_get_sections(): void
    {
        $topo = $this->template('Topo de bolo', [['key' => 'nome', 'label' => 'Nome no topo', 'type' => 'text']]);
        $caneca = $this->template('Caneca', [['key' => 'nome', 'label' => 'Nome na caneca', 'type' => 'text']]);
        $a = $this->product('Topo', ['art_brief_enabled' => true, 'art_brief_template_id' => $topo]);
        $b = $this->product('Caneca', ['art_brief_enabled' => true, 'art_brief_template_id' => $caneca]);

        $this->botOrder([$a, $b], 'msg-3')->assertCreated();

        $questions = collect(ArtBrief::first()->questions);
        $this->assertSame(['s1_nome', 's2_nome'], $questions->pluck('key')->all());
        $this->assertSame(['Topo de bolo', 'Caneca'], $questions->pluck('section')->all());
    }

    public function test_product_cannot_use_another_store_template(): void
    {
        $other = Company::create(['name' => 'Outra', 'slug' => 'outra-modelos', 'plan' => Company::PLAN_PRO]);
        $foreign = \App\Models\ArtBriefTemplate::create(['company_id' => $other->id, 'name' => 'X', 'questions' => []]);

        $this->api()->postJson('/api/v1/products', ['name' => 'P', 'cost' => 5, 'art_brief_enabled' => true, 'art_brief_template_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('art_brief_template_id');
    }
}
