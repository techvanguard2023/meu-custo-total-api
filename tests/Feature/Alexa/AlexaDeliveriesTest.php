<?php

namespace Tests\Feature\Alexa;

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use App\Services\Alexa\AlexaRequestVerifier;
use App\Support\Delivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AlexaDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-13 09:00', Delivery::TZ));
        $this->app->instance(AlexaRequestVerifier::class, new class extends AlexaRequestVerifier
        {
            public function failure(string $body, ?string $certUrl, ?string $signature): ?string
            {
                return null;
            }
        });

        $company = Company::create(['name' => 'Loja Teste', 'slug' => 'loja-teste-alexa', 'plan' => Company::PLAN_PRO]);
        $this->user = User::factory()->create(['company_id' => $company->id]);
    }

    public function test_tells_today_deliveries_without_prices(): void
    {
        $this->quote(['name' => 'Kit Chaveiros', 'delivery_date' => '2026-11-13', 'delivery_time' => '14:00', 'delivery_method' => 'pickup', 'final_price' => 123.45]);
        $this->quote(['name' => 'Vaso', 'delivery_date' => '2026-11-14']);
        $this->quote(['name' => 'Caneca', 'delivery_date' => '2026-11-10']);

        $speech = $this->ask('EntregasPorDataIntent')->assertOk()->json('response.outputSpeech.text');

        $this->assertSame('Hoje, você tem 1 entrega. Às 14 horas, 1 Kit Chaveiros, retirada. Atenção: 1 entrega atrasada.', $speech);
        $this->assertStringNotContainsString('123', $speech);
    }

    public function test_next_skips_delivered_and_past_times(): void
    {
        $this->quote(['name' => 'Já passou', 'delivery_date' => '2026-11-13', 'delivery_time' => '08:00']);
        $this->quote(['name' => 'Entregue', 'delivery_date' => '2026-11-13', 'delivery_time' => '10:00', 'production_status' => Quote::PRODUCTION_DELIVERED]);
        $this->quote(['name' => 'Vaso', 'delivery_date' => '2026-11-13', 'delivery_time' => '11:00']);

        $this->ask('ProximaEntregaIntent')->assertJsonPath('response.outputSpeech.text', 'Sua próxima entrega é hoje, às 11 horas: 1 Vaso.');
    }

    public function test_other_store_deliveries_never_leak(): void
    {
        $other = Company::create(['name' => 'Outra', 'slug' => 'outra-loja-alexa', 'plan' => Company::PLAN_PRO]);
        Quote::forceCreate(['company_id' => $other->id, 'name' => 'Segredo', 'status' => Quote::STATUS_APPROVED, 'production_status' => Quote::PRODUCTION_PENDING, 'delivery_date' => '2026-11-13', 'quantity' => 1, 'final_price' => 10, 'print_time_minutes' => 0, 'material_weight_g' => 0]);

        $this->ask('EntregasPorDataIntent')->assertJsonPath('response.outputSpeech.text', 'Você não tem entregas para hoje.');
    }

    public function test_non_pro_store_is_told_about_the_plan(): void
    {
        $this->user->company->update(['plan' => Company::PLAN_ESSENTIAL]);

        $this->ask('EntregasPorDataIntent')->assertJsonPath('response.outputSpeech.text', 'Consultar as entregas pela Alexa é um recurso do plano Pro do Meu Custo Total.');
    }

    public function test_only_alexa_tokens_are_accepted(): void
    {
        $login = $this->user->createToken('api')->plainTextToken;

        $this->ask('EntregasPorDataIntent', $login)->assertJsonPath('response.card.type', 'LinkAccount');
        $this->ask('EntregasPorDataIntent', '1|nao-existe')->assertJsonPath('response.card.type', 'LinkAccount');
    }

    public function test_demo_deliveries_are_renewed_around_today(): void
    {
        $this->quote(['name' => 'Venda de verdade', 'delivery_date' => '2026-11-20']);

        $this->artisan('alexa:demo-deliveries', ['email' => $this->user->email])->assertSuccessful();
        $this->artisan('alexa:demo-deliveries', ['email' => $this->user->email])->assertSuccessful();

        $this->assertSame(6, Quote::where('company_id', $this->user->company_id)->count());
        $this->ask('EntregasPorDataIntent')->assertJsonPath(
            'response.outputSpeech.text',
            'Hoje, você tem 2 entregas. Às 10 horas, 1 Kit 10 chaveiros personalizados para Ana, retirada. Às 15 e 30, 1 Vaso geométrico branco para Bruno, entrega. Atenção: 1 entrega atrasada.'
        );
    }

    public function test_long_periods_keep_the_card_short(): void
    {
        foreach (range(1, 35) as $i) {
            $this->quote(['name' => "Item {$i}", 'delivery_date' => '2026-11-20']);
        }

        $card = $this->ask('EntregasPorDataIntent', slots: ['data' => ['name' => 'data', 'value' => '2026-11']])->json('response.card.content');

        $this->assertSame(31, substr_count($card, "\n") + 1);
        $this->assertStringContainsString('e mais 5 na Agenda', $card);
    }

    public function test_alexa_token_cannot_open_other_routes(): void
    {
        $token = $this->user->createToken('alexa')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/agenda?from=2026-11-13&to=2026-11-13')->assertForbidden();
    }

    private function ask(string $intent, ?string $token = null, array $slots = [])
    {
        $token ??= $this->user->createToken('alexa')->plainTextToken;

        return $this->postJson('/api/v1/alexa', [
            'version' => '1.0',
            'context' => ['System' => ['application' => ['applicationId' => 'x'], 'user' => ['userId' => 'amzn1.ask.account.X', 'accessToken' => $token]]],
            'request' => ['type' => 'IntentRequest', 'timestamp' => now()->toIso8601ZuluString(), 'intent' => ['name' => $intent, 'slots' => $slots]],
        ]);
    }

    private function quote(array $attributes): Quote
    {
        return Quote::forceCreate($attributes + [
            'company_id' => $this->user->company_id,
            'status' => Quote::STATUS_APPROVED,
            'production_status' => Quote::PRODUCTION_PENDING,
            'quantity' => 1,
            'final_price' => 10,
            'print_time_minutes' => 0,
            'material_weight_g' => 0,
        ]);
    }
}
