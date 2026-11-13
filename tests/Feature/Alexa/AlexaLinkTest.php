<?php

namespace Tests\Feature\Alexa;

use App\Models\Company;
use App\Models\User;
use App\Services\Alexa\AlexaRequestVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlexaLinkTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://pitangui.amazon.com/api/skill/link/MTESTE';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.alexa.client_id' => 'meu-custo-total-alexa',
            'services.alexa.client_secret' => 'segredo-de-teste',
            'services.alexa.redirect_uris' => self::REDIRECT.', https://layla.amazon.com/api/skill/link/MTESTE',
        ]);
        $this->app->instance(AlexaRequestVerifier::class, new class extends AlexaRequestVerifier
        {
            public function failure(string $body, ?string $certUrl, ?string $signature): ?string
            {
                return null;
            }
        });

        $company = Company::create(['name' => 'Loja Teste', 'slug' => 'loja-teste-link', 'plan' => Company::PLAN_PRO]);
        $this->user = User::factory()->create(['company_id' => $company->id]);
    }

    public function test_authorize_returns_amazon_redirect_with_code_and_state(): void
    {
        $redirect = $this->authorize()->assertOk()->json('redirect');

        $this->assertStringStartsWith(self::REDIRECT.'?state=estado-xyz&code=', $redirect);
    }

    public function test_authorize_rejects_unknown_client_or_redirect(): void
    {
        $this->authorize(['client_id' => 'outro'])->assertStatus(422);
        $this->authorize(['redirect_uri' => 'https://evil.example.com/callback'])->assertStatus(422);
    }

    public function test_authorize_needs_login_and_pro(): void
    {
        $this->postJson('/api/v1/alexa/authorize', $this->authorizeParams())->assertUnauthorized();

        $this->user->company->update(['plan' => Company::PLAN_ESSENTIAL]);
        $this->authorize()->assertForbidden();
    }

    public function test_code_becomes_tokens_once(): void
    {
        $code = $this->code();

        $tokens = $this->exchange($code)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame('bearer', $tokens['token_type']);
        $this->assertSame(86400, $tokens['expires_in']);
        $this->assertNotEmpty($tokens['access_token']);
        $this->assertNotEmpty($tokens['refresh_token']);

        $this->exchange($code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_token_endpoint_checks_client_and_redirect(): void
    {
        $this->exchange($this->code(), secret: 'errado')->assertStatus(401)->assertJsonPath('error', 'invalid_client');
        $this->exchange($this->code(), redirect: 'https://layla.amazon.com/api/skill/link/MTESTE')->assertJsonPath('error', 'invalid_grant');
        $this->post('/api/v1/alexa/token', ['grant_type' => 'password', 'client_id' => 'meu-custo-total-alexa', 'client_secret' => 'segredo-de-teste'])
            ->assertJsonPath('error', 'unsupported_grant_type');
    }

    public function test_credentials_in_body_also_work(): void
    {
        $this->post('/api/v1/alexa/token', [
            'grant_type' => 'authorization_code',
            'code' => $this->code(),
            'redirect_uri' => self::REDIRECT,
            'client_id' => 'meu-custo-total-alexa',
            'client_secret' => 'segredo-de-teste',
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_linked_token_answers_on_the_skill_and_expired_does_not(): void
    {
        $access = $this->exchange($this->code())->json('access_token');

        $this->ask($access)->assertJsonPath('response.outputSpeech.text', 'Você não tem entregas para hoje.');

        $this->travel(25)->hours();
        $this->ask($access)->assertJsonPath('response.card.type', 'LinkAccount');
    }

    public function test_refresh_rotates_both_tokens(): void
    {
        $first = $this->exchange($this->code())->json();

        $second = $this->withBasicAuth('meu-custo-total-alexa', 'segredo-de-teste')
            ->post('/api/v1/alexa/token', ['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']])
            ->assertOk()->json();

        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->ask($first['access_token'])->assertJsonPath('response.card.type', 'LinkAccount');
        $this->ask($second['access_token'])->assertJsonPath('response.outputSpeech.text', 'Você não tem entregas para hoje.');

        $this->withBasicAuth('meu-custo-total-alexa', 'segredo-de-teste')
            ->post('/api/v1/alexa/token', ['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']])
            ->assertJsonPath('error', 'invalid_grant');
    }

    public function test_status_and_unlink(): void
    {
        $login = $this->user->createToken('api')->plainTextToken;
        $this->withToken($login)->getJson('/api/v1/alexa/link')->assertJsonPath('linked', false);

        $access = $this->exchange($this->code())->json('access_token');
        $this->withToken($login)->getJson('/api/v1/alexa/link')->assertJsonPath('linked', true);

        $this->withToken($login)->deleteJson('/api/v1/alexa/link')->assertNoContent();
        $this->withToken($login)->getJson('/api/v1/alexa/link')->assertJsonPath('linked', false);
        $this->ask($access)->assertJsonPath('response.card.type', 'LinkAccount');
    }

    public function test_alexa_tokens_stay_out_of_integration_tokens(): void
    {
        $this->exchange($this->code());
        $login = $this->user->createToken('api')->plainTextToken;

        $this->withToken($login)->getJson('/api/v1/integration-tokens')->assertOk()->assertJsonCount(0);
        $this->withToken($login)->postJson('/api/v1/integration-tokens', ['name' => 'Alexa'])->assertStatus(422);
        $this->withToken($login)->postJson('/api/v1/integration-tokens', ['name' => 'alexa:refresh'])->assertStatus(422);
    }

    private function authorizeParams(array $override = []): array
    {
        return $override + ['client_id' => 'meu-custo-total-alexa', 'redirect_uri' => self::REDIRECT, 'state' => 'estado-xyz', 'response_type' => 'code'];
    }

    private function authorize(array $override = [])
    {
        return $this->withToken($this->user->createToken('api')->plainTextToken)->postJson('/api/v1/alexa/authorize', $this->authorizeParams($override));
    }

    private function code(): string
    {
        parse_str((string) parse_url($this->authorize()->json('redirect'), PHP_URL_QUERY), $query);
        $this->app['auth']->forgetGuards();

        return $query['code'];
    }

    private function exchange(string $code, string $secret = 'segredo-de-teste', string $redirect = self::REDIRECT)
    {
        $this->app['auth']->forgetGuards();

        return $this->withBasicAuth('meu-custo-total-alexa', $secret)
            ->post('/api/v1/alexa/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]);
    }

    private function ask(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/alexa', [
            'version' => '1.0',
            'context' => ['System' => ['application' => ['applicationId' => 'x'], 'user' => ['userId' => 'amzn1.ask.account.X', 'accessToken' => $token]]],
            'request' => ['type' => 'IntentRequest', 'timestamp' => now()->toIso8601ZuluString(), 'intent' => ['name' => 'EntregasPorDataIntent', 'slots' => []]],
        ]);
    }
}
