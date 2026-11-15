<?php

namespace Tests\Feature;

use App\Models\ArtProof;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtProofTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private Quote $quote;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.frontend_url' => 'https://app.exemplo.com.br']);

        $company = Company::create(['name' => 'Loja Arte', 'slug' => 'loja-arte', 'plan' => Company::PLAN_PRO]);
        $this->user = User::factory()->create(['company_id' => $company->id]);
        $this->token = $this->user->createToken('api')->plainTextToken;
        $this->quote = $this->makeQuote($company);
    }

    private function makeQuote(Company $company, string $status = Quote::STATUS_SENT): Quote
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Maria Souza', 'phone' => '21999990000']);

        return Quote::forceCreate([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'name' => 'Convite de aniversário', 'status' => $status,
            'quantity' => 1, 'final_price' => 50, 'print_time_minutes' => 0, 'material_weight_g' => 0,
        ]);
    }

    private function files(int $count = 1): array
    {
        return array_map(fn ($i) => UploadedFile::fake()->image("arte-{$i}.png", 300, 300), range(1, $count));
    }

    private function send(array $extra = [], ?Quote $quote = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->post('/api/v1/quotes/'.($quote ?? $this->quote)->id.'/art-proofs', $extra + ['files' => $this->files()], ['Accept' => 'application/json']);
    }

    public function test_store_creates_version_one_with_link_and_default_terms(): void
    {
        $response = $this->send(['message' => 'Confere o nome da festa'])->assertCreated();

        $response->assertJsonPath('version', 1)->assertJsonPath('status', 'pending')->assertJsonPath('message', 'Confere o nome da festa')
            ->assertJsonPath('terms', ArtProof::DEFAULT_TERMS)->assertJsonCount(1, 'files');
        $this->assertStringStartsWith('https://app.exemplo.com.br/arte/', $response->json('url'));
        $this->assertSame(40, strlen(basename($response->json('url'))));

        $path = ArtProof::first()->files->first()->path;
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('art-proofs/'.$this->user->company_id.'/', $path);
    }

    public function test_a_new_version_supersedes_the_previous_one(): void
    {
        $first = $this->send()->json();
        $second = $this->send()->json();

        $this->assertSame(2, $second['version']);
        $this->assertSame('superseded', ArtProof::find($first['id'])->status);
        $this->assertSame('pending', ArtProof::find($second['id'])->status);

        $index = $this->withToken($this->token)->getJson('/api/v1/quotes/'.$this->quote->id.'/art-proofs')->assertOk()->json();
        $this->assertSame([2, 1], array_column($index['proofs'], 'version'));
    }

    public function test_an_approved_version_is_not_superseded(): void
    {
        $first = ArtProof::find($this->send()->json('id'));
        $first->update(['status' => ArtProof::APPROVED]);

        $this->send()->assertCreated();

        $this->assertSame('approved', $first->fresh()->status);
    }

    public function test_upload_validation(): void
    {
        $this->send(['files' => []])->assertStatus(422)->assertJsonValidationErrors('files');
        $this->send(['files' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertStatus(422)->assertJsonValidationErrors('files.0');
        $this->send(['files' => [UploadedFile::fake()->create('desenho.svg', 10, 'image/svg+xml')]])->assertStatus(422);
        $this->send(['files' => [UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf')]])->assertStatus(422)->assertJsonValidationErrors('files.0');
        // Executável renomeado pra .png: o conteúdo não é de imagem
        $fake = tempnam(sys_get_temp_dir(), 'falso');
        file_put_contents($fake, 'MZ isto nao e uma imagem, e texto puro');
        $this->send(['files' => [new UploadedFile($fake, 'falso.png', 'image/png', null, true)]])->assertStatus(422)->assertJsonValidationErrors('files.0');
        @unlink($fake);
        $this->send(['files' => $this->files(9)])->assertStatus(422)->assertJsonValidationErrors('files');
        $this->send(['model_url' => 'nao-e-link'])->assertStatus(422)->assertJsonValidationErrors('model_url');

        $this->send(['files' => [UploadedFile::fake()->create('arte.pdf', 200, 'application/pdf'), UploadedFile::fake()->create('peca.stl', 500, 'application/octet-stream')]])->assertCreated();
    }

    public function test_custom_company_terms_and_per_send_terms(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->putJson('/api/v1/art-approval/terms', ['terms' => 'Termo da minha loja.'])->assertOk()->assertJsonPath('terms', 'Termo da minha loja.');

        $this->assertSame('Termo da minha loja.', ArtProof::find($this->send()->json('id'))->terms);
        $this->assertSame('Só este envio.', ArtProof::find($this->send(['terms' => 'Só este envio.'])->json('id'))->terms);

        // Em branco (ou igual ao padrão) volta pro texto do sistema
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->putJson('/api/v1/art-approval/terms', ['terms' => ''])->assertOk()->assertJsonPath('terms', null);
        $this->assertSame(ArtProof::DEFAULT_TERMS, ArtProof::find($this->send()->json('id'))->terms);
    }

    public function test_public_page_shows_the_proof_and_marks_it_viewed(): void
    {
        $proof = ArtProof::find($this->send(['message' => 'Olha só', 'model_url' => 'https://exemplo.com/modelo'])->json('id'));
        $this->assertNull($proof->viewed_at);

        $this->getJson('/api/v1/public/art-proof/'.$proof->token)->assertOk()
            ->assertJsonPath('status', 'pending')->assertJsonPath('can_respond', true)->assertJsonPath('store.name', 'Loja Arte')
            ->assertJsonPath('order_name', 'Convite de aniversário')->assertJsonPath('customer_first_name', 'Maria')
            ->assertJsonPath('message', 'Olha só')->assertJsonPath('model_url', 'https://exemplo.com/modelo')
            ->assertJsonPath('terms', ArtProof::DEFAULT_TERMS)->assertJsonCount(1, 'files')->assertJsonPath('files.0.is_image', true);

        $this->assertNotNull($proof->fresh()->viewed_at);
        $this->getJson('/api/v1/public/art-proof/token-que-nao-existe')->assertNotFound();
    }

    public function test_customer_approves_and_the_proof_is_recorded(): void
    {
        $proof = ArtProof::find($this->send()->json('id'));

        $this->postJson('/api/v1/public/art-proof/'.$proof->token.'/approve', ['name' => 'Ma'])->assertStatus(422)->assertJsonValidationErrors(['name', 'accept_terms']);
        $this->postJson('/api/v1/public/art-proof/'.$proof->token.'/approve', ['name' => 'Maria Souza'])->assertStatus(422)->assertJsonValidationErrors('accept_terms');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'TesteNavegador/1.0'])
            ->postJson('/api/v1/public/art-proof/'.$proof->token.'/approve', ['name' => 'Maria Souza', 'accept_terms' => true])
            ->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('can_respond', false)->assertJsonPath('approved.name', 'Maria Souza');

        $proof->refresh();
        $this->assertSame('approved', $proof->status);
        $this->assertSame('Maria Souza', $proof->approver_name);
        $this->assertSame('203.0.113.9', $proof->approver_ip);
        $this->assertSame('TesteNavegador/1.0', $proof->approver_user_agent);
        $this->assertNotNull($proof->responded_at);

        // Só responde uma vez
        $this->postJson('/api/v1/public/art-proof/'.$proof->token.'/approve', ['name' => 'Outra Pessoa', 'accept_terms' => true])->assertStatus(409);
        $this->postJson('/api/v1/public/art-proof/'.$proof->token.'/changes', ['comment' => 'Mudei de ideia'])->assertStatus(409);
        $this->assertSame('Maria Souza', $proof->fresh()->approver_name);
    }

    public function test_customer_asks_for_changes(): void
    {
        $proof = ArtProof::find($this->send()->json('id'));

        $this->postJson('/api/v1/public/art-proof/'.$proof->token.'/changes', ['comment' => ''])->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->postJson('/api/v1/public/art-proof/'.$proof->token.'/changes', ['comment' => 'Trocar a cor do título para azul'])
            ->assertOk()->assertJsonPath('status', 'changes_requested')->assertJsonPath('can_respond', false)->assertJsonPath('comment', 'Trocar a cor do título para azul');

        $this->assertSame('changes_requested', $proof->fresh()->status);

        // A loja vê o pedido de ajuste e manda a versão 2
        $listed = $this->withToken($this->token)->getJson('/api/v1/quotes/'.$this->quote->id.'/art-proofs')->json('proofs.0');
        $this->assertSame('Trocar a cor do título para azul', $listed['customer_comment']);
        $this->assertSame(2, $this->send()->json('version'));
    }

    public function test_a_superseded_version_cannot_be_answered(): void
    {
        $old = ArtProof::find($this->send()->json('id'));
        $this->send();

        $this->postJson('/api/v1/public/art-proof/'.$old->token.'/approve', ['name' => 'Maria Souza', 'accept_terms' => true])->assertStatus(409);
        $this->getJson('/api/v1/public/art-proof/'.$old->token)->assertOk()->assertJsonPath('status', 'superseded')->assertJsonPath('can_respond', false);
    }

    public function test_delete_removes_files_but_an_approved_proof_stays(): void
    {
        $pending = ArtProof::find($this->send()->json('id'));
        $path = $pending->files->first()->path;
        Storage::disk('public')->assertExists($path);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->deleteJson('/api/v1/art-proofs/'.$pending->id)->assertNoContent();
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseCount('art_proofs', 0);

        $approved = ArtProof::find($this->send()->json('id'));
        $approved->update(['status' => ArtProof::APPROVED]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->deleteJson('/api/v1/art-proofs/'.$approved->id)->assertStatus(422);
    }

    public function test_deleting_the_quote_cleans_its_files(): void
    {
        $path = ArtProof::find($this->send()->json('id'))->files->first()->path;

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->deleteJson('/api/v1/quotes/'.$this->quote->id)->assertNoContent();

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseCount('art_proofs', 0);
    }

    public function test_free_plan_and_other_stores_are_blocked(): void
    {
        $other = Company::create(['name' => 'Outra Loja', 'slug' => 'outra-loja-arte', 'plan' => Company::PLAN_PRO]);
        $otherQuote = $this->makeQuote($other);
        $this->send([], $otherQuote)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/v1/quotes/'.$otherQuote->id.'/art-proofs')->assertForbidden();

        $proof = ArtProof::create(['company_id' => $other->id, 'quote_id' => $otherQuote->id, 'token' => str_repeat('a', 40), 'terms' => 'x']);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->deleteJson('/api/v1/art-proofs/'.$proof->id)->assertForbidden();
        $this->withToken($this->token)->postJson('/api/v1/art-proofs/'.$proof->id.'/send')->assertForbidden();

        $this->user->company->update(['plan' => Company::PLAN_FREE]);
        $this->send()->assertForbidden();
    }

    public function test_send_endpoint_reports_when_whatsapp_is_not_connected(): void
    {
        $proof = ArtProof::find($this->send()->json('id'));

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/art-proofs/'.$proof->id.'/send')
            ->assertOk()->assertJsonPath('sent', false)->assertJsonPath('url', $proof->publicUrl());

        $proof->update(['status' => ArtProof::APPROVED]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/art-proofs/'.$proof->id.'/send')->assertStatus(422);
    }

    public function test_latest_proof_travels_with_the_quote_but_without_private_fields(): void
    {
        $this->send();
        $second = ArtProof::find($this->send(['message' => 'segredo interno'])->json('id'));

        $latest = $this->quote->fresh()->load('latestArtProof')->toArray()['latest_art_proof'];

        $this->assertSame($second->id, $latest['id']);
        $this->assertSame('pending', $latest['status']);
        $this->assertArrayNotHasKey('terms', $latest);
        $this->assertArrayNotHasKey('token', $latest);
        $this->assertArrayNotHasKey('approver_ip', $latest);
        $this->assertArrayNotHasKey('message', $latest);
    }
}
