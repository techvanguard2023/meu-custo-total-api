<?php

namespace Tests\Feature;

use App\Models\ArtBrief;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Briefing da arte: perguntas do nicho editáveis pela loja, link do pedido, respostas do cliente e aviso no menu. */
class ArtBriefTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Quote $quote;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->company = Company::create(['name' => 'Papelaria Festa', 'slug' => 'papelaria-festa', 'plan' => Company::PLAN_PRO, 'segment' => 'stationery']);
        $this->token = User::factory()->create(['company_id' => $this->company->id])->createToken('api')->plainTextToken;
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Kelly Souza', 'phone' => '21988887777']);
        $this->quote = Quote::forceCreate([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'name' => 'Kit festa', 'status' => Quote::STATUS_APPROVED,
            'production_status' => Quote::PRODUCTION_PENDING, 'quantity' => 1, 'final_price' => 120, 'amount_paid' => 0,
            'print_time_minutes' => 0, 'material_weight_g' => 0, 'approved_at' => now(),
        ]);
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    private function link(): string
    {
        $url = $this->api()->postJson("/api/v1/quotes/{$this->quote->id}/art-brief")->assertCreated()->json('brief.url');

        return basename($url);
    }

    public function test_store_starts_with_the_niche_questions(): void
    {
        $keys = collect($this->api()->getJson('/api/v1/art-brief/questions')->assertOk()->assertJsonPath('customized', false)->json('questions'))->pluck('key');

        $this->assertTrue($keys->contains('tema') && $keys->contains('aniversariante') && $keys->contains('nomes'));
        $this->company->update(['segment' => 'bakery']);
        $this->assertTrue(collect($this->api()->getJson('/api/v1/art-brief/questions')->json('questions'))->pluck('key')->contains('recheio'));
    }

    public function test_store_can_add_remove_and_restore_questions(): void
    {
        $questions = $this->api()->getJson('/api/v1/art-brief/questions')->json('questions');
        $questions = array_values(array_filter($questions, fn ($q) => $q['key'] !== 'local_evento'));
        $questions[0]['enabled'] = false;
        $questions[] = ['label' => 'Qual o nome do bebê?', 'type' => 'text', 'required' => true];

        $saved = $this->api()->putJson('/api/v1/art-brief/questions', ['questions' => $questions])->assertOk()->assertJsonPath('customized', true)->json('questions');
        $this->assertFalse(collect($saved)->pluck('key')->contains('local_evento'));
        $this->assertFalse($saved[0]['enabled']);
        $this->assertStringStartsWith('p_', end($saved)['key']);

        $this->api()->putJson('/api/v1/art-brief/questions', ['questions' => [['label' => 'Opção?', 'type' => 'choice', 'options' => ['Só uma']]]])
            ->assertStatus(422);
        $this->api()->putJson('/api/v1/art-brief/questions', ['questions' => null])->assertOk()->assertJsonPath('customized', false);
    }

    public function test_customer_answers_with_files_and_the_store_sees_it(): void
    {
        $token = $this->link();
        $page = $this->getJson("/api/v1/public/art-brief/{$token}")->assertOk()->assertJsonPath('customer_first_name', 'Kelly');
        $this->assertNotEmpty($page->json('questions'));

        $file = $this->post("/api/v1/public/art-brief/{$token}/files", ['question_key' => 'referencias', 'file' => UploadedFile::fake()->image('ref.jpg')])
            ->assertCreated()->json();

        // Obrigatória sem resposta: recusa
        $this->postJson("/api/v1/public/art-brief/{$token}", ['answers' => []])->assertStatus(422);

        $this->postJson("/api/v1/public/art-brief/{$token}", ['answers' => [
            'tema' => 'Safari',
            'cores' => ['#ff0000', 'azul', '#00FF00'],
            'nomes' => ['Ana', ' ', 'João'],
            'referencias' => ['links' => ['https://pinterest.com/pin/1', 'javascript:alert(1)']],
            'pergunta_que_nao_existe' => 'x',
        ]])->assertOk()->assertJsonPath('status', ArtBrief::SUBMITTED)->assertJsonPath('can_answer', false);

        $brief = ArtBrief::first();
        $this->assertSame(['tema' => 'Safari', 'cores' => ['#FF0000', '#00FF00'], 'nomes' => ['Ana', 'João'], 'referencias' => ['links' => ['https://pinterest.com/pin/1']]], $brief->answers);

        // Aviso no menu até a loja abrir o briefing
        $this->api()->getJson('/api/v1/quotes/new-orders')->assertJsonPath('briefs', 1);
        $this->api()->getJson("/api/v1/quotes/{$this->quote->id}/art-brief")->assertOk()
            ->assertJsonPath('brief.answers.tema', 'Safari')->assertJsonPath('brief.files.0.id', $file['id']);
        $this->api()->getJson('/api/v1/quotes/new-orders')->assertJsonPath('briefs', 0);

        // Já enviado: não muda mais, e um novo link só com renew
        $this->postJson("/api/v1/public/art-brief/{$token}", ['answers' => ['tema' => 'Outro']])->assertStatus(409);
        $this->api()->postJson("/api/v1/quotes/{$this->quote->id}/art-brief")->assertStatus(422);
        $this->api()->postJson("/api/v1/quotes/{$this->quote->id}/art-brief", ['renew' => true])->assertCreated()->assertJsonPath('brief.status', ArtBrief::PENDING);
    }

    public function test_answers_keep_the_questions_of_when_the_link_was_sent(): void
    {
        $token = $this->link();
        $this->api()->putJson('/api/v1/art-brief/questions', ['questions' => [['key' => 'outra', 'label' => 'Outra pergunta', 'type' => 'text']]])->assertOk();

        // O link aberto acompanha as perguntas atuais só quando a loja gera de novo
        $this->assertSame('tema', $this->getJson("/api/v1/public/art-brief/{$token}")->json('questions.0.key'));
        $this->link();
        $this->assertSame('outra', $this->getJson("/api/v1/public/art-brief/{$token}")->json('questions.0.key'));
    }

    public function test_other_store_cannot_see_the_brief(): void
    {
        $this->link();
        $other = Company::create(['name' => 'Outra', 'slug' => 'outra', 'plan' => Company::PLAN_PRO]);
        $this->token = User::factory()->create(['company_id' => $other->id])->createToken('api')->plainTextToken;

        $this->api()->getJson("/api/v1/quotes/{$this->quote->id}/art-brief")->assertForbidden();
    }
}
