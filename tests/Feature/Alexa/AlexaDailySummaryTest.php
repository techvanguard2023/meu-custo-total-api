<?php

namespace Tests\Feature\Alexa;

use App\Http\Controllers\Api\AlexaController;
use App\Models\AlexaReminder;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use App\Services\Alexa\AlexaRequestVerifier;
use App\Services\Alexa\AlexaSpeech;
use App\Support\Delivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlexaDailySummaryTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.amazonalexa.com';

    private const ALEXA_USER = 'amzn1.ask.account.TESTE';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-13 06:00', Delivery::TZ));
        config(['services.alexa.messaging_client_id' => 'msg-id', 'services.alexa.messaging_client_secret' => 'msg-secret']);
        $this->app->instance(AlexaRequestVerifier::class, new class extends AlexaRequestVerifier
        {
            public function failure(string $body, ?string $certUrl, ?string $signature): ?string
            {
                return null;
            }
        });

        $company = Company::create(['name' => 'Loja Teste', 'slug' => 'loja-teste-resumo', 'plan' => Company::PLAN_PRO]);
        $this->user = User::factory()->create(['company_id' => $company->id]);
        Quote::forceCreate([
            'company_id' => $company->id, 'name' => 'Vaso', 'status' => Quote::STATUS_APPROVED, 'production_status' => Quote::PRODUCTION_PENDING,
            'delivery_date' => '2026-11-13', 'delivery_time' => '10:00', 'quantity' => 1, 'final_price' => 10, 'print_time_minutes' => 0, 'material_weight_g' => 0,
        ]);
    }

    public function test_without_permission_sends_consent_card(): void
    {
        Http::fake();

        $this->ask('LembreteDiarioIntent', granted: false)
            ->assertJsonPath('response.card.type', 'AskForPermissionsConsent')
            ->assertJsonPath('response.card.permissions.0', 'alexa::alerts:reminders:skill:readwrite');

        Http::assertNothingSent();
        $this->assertDatabaseCount('alexa_reminders', 0);
    }

    public function test_creates_daily_reminder_with_todays_deliveries(): void
    {
        Http::fake([self::API.'/v1/alerts/reminders' => Http::response(['alertToken' => 'tok-1'], 201)]);

        $this->ask('LembreteDiarioIntent')->assertJsonPath('response.outputSpeech.text', 'Combinado! Todo dia às 8 horas eu te digo as entregas do dia. Para parar, é só pedir: desative o resumo diário.');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer api-token')
                && $request['trigger']['timeZoneId'] === 'America/Sao_Paulo'
                && $request['trigger']['recurrence']['startDateTime'] === '2026-11-13T08:00:00.000'
                && $request['trigger']['recurrence']['recurrenceRules'][0] === 'FREQ=DAILY;BYHOUR=8;BYMINUTE=0;BYSECOND=0;INTERVAL=1;'
                && $request['alertInfo']['spokenInfo']['content'][0]['text'] === 'Bom dia! Hoje você tem 1 entrega. Às 10 horas, 1 Vaso.';
        });
        $this->assertDatabaseHas('alexa_reminders', ['user_id' => $this->user->id, 'alert_token' => 'tok-1', 'remind_at' => '08:00', 'alexa_user_id' => self::ALEXA_USER]);
    }

    public function test_asking_again_changes_the_time_of_the_same_reminder(): void
    {
        $this->reminder();
        Http::fake([self::API.'/v1/alerts/reminders/tok-1' => Http::response(['alertToken' => 'tok-1'])]);

        $this->ask('LembreteDiarioIntent', slots: ['hora' => ['name' => 'hora', 'value' => '18:30']])
            ->assertJsonPath('response.outputSpeech.text', 'Combinado! Todo dia às 18 e 30 eu te digo as entregas do dia. Para parar, é só pedir: desative o resumo diário.');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['trigger']['recurrence']['recurrenceRules'][0] === 'FREQ=DAILY;BYHOUR=18;BYMINUTE=30;BYSECOND=0;INTERVAL=1;');
        $this->assertDatabaseHas('alexa_reminders', ['user_id' => $this->user->id, 'alert_token' => 'tok-1', 'remind_at' => '18:30']);
    }

    public function test_reminder_deleted_on_the_device_is_created_again(): void
    {
        $this->reminder();
        Http::fake([
            self::API.'/v1/alerts/reminders/tok-1' => Http::response([], 404),
            self::API.'/v1/alerts/reminders' => Http::response(['alertToken' => 'tok-2'], 201),
        ]);

        $this->ask('LembreteDiarioIntent')->assertOk();

        $this->assertDatabaseHas('alexa_reminders', ['user_id' => $this->user->id, 'alert_token' => 'tok-2']);
    }

    public function test_cancel_deletes_the_reminder(): void
    {
        $this->reminder();
        Http::fake([self::API.'/v1/alerts/reminders/tok-1' => Http::response([], 200)]);

        $this->ask('CancelarLembreteDiarioIntent')->assertJsonPath('response.outputSpeech.text', 'Pronto, desativei o resumo diário.');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
        $this->assertDatabaseCount('alexa_reminders', 0);
        $this->ask('CancelarLembreteDiarioIntent')->assertJsonPath('response.outputSpeech.text', 'O resumo diário não está ativado.');
    }

    public function test_hourly_command_wakes_the_skill_for_each_reminder(): void
    {
        $reminder = $this->reminder();
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'lwa-token', 'expires_in' => 3600]),
            self::API.'/v1/skillmessages/users/*' => Http::response([], 202),
        ]);

        $this->artisan('alexa:daily-summaries')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.amazon.com/auth/o2/token' && $request['scope'] === 'alexa:skill_messaging');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/skillmessages/users/'.rawurlencode(self::ALEXA_USER))
            && $request->hasHeader('Authorization', 'Bearer lwa-token')
            && $request['data'] === ['type' => 'daily_summary', 'reminder_id' => $reminder->id]);
    }

    public function test_command_does_nothing_without_messaging_credentials(): void
    {
        config(['services.alexa.messaging_client_id' => null]);
        $this->reminder();
        Http::fake();

        $this->artisan('alexa:daily-summaries')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_message_from_amazon_refreshes_the_text(): void
    {
        $reminder = $this->reminder();
        Http::fake([self::API.'/v1/alerts/reminders/tok-1' => Http::response(['alertToken' => 'tok-1'])]);

        $this->message(['type' => 'daily_summary', 'reminder_id' => $reminder->id])->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request['alertInfo']['spokenInfo']['content'][0]['text'] === 'Bom dia! Hoje você tem 1 entrega. Às 10 horas, 1 Vaso.');
        $this->assertNotNull($reminder->fresh()->last_synced_at);
    }

    public function test_message_for_another_alexa_user_is_ignored(): void
    {
        $reminder = $this->reminder();
        Http::fake();

        $this->message(['type' => 'daily_summary', 'reminder_id' => $reminder->id], alexaUser: 'amzn1.ask.account.OUTRO')->assertOk();

        Http::assertNothingSent();
    }

    public function test_reminder_removed_on_the_device_is_forgotten(): void
    {
        $reminder = $this->reminder();
        Http::fake([self::API.'/v1/alerts/reminders/tok-1' => Http::response([], 404)]);

        $this->message(['type' => 'daily_summary', 'reminder_id' => $reminder->id])->assertOk();

        $this->assertDatabaseCount('alexa_reminders', 0);
    }

    public function test_unlink_removes_the_reminder_from_the_device(): void
    {
        $this->reminder();
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'lwa-token', 'expires_in' => 3600]),
            self::API.'/v1/skillmessages/users/*' => Http::response([], 202),
        ]);
        $login = $this->user->createToken('api')->plainTextToken;

        $this->withToken($login)->getJson('/api/v1/alexa/link')->assertJsonPath('daily_summary_at', '08:00');
        $this->withToken($login)->deleteJson('/api/v1/alexa/link')->assertNoContent();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/skillmessages/') && $request['data'] === ['type' => 'remove', 'alert_token' => 'tok-1']);
        $this->assertDatabaseCount('alexa_reminders', 0);
    }

    public function test_parse_time_slot(): void
    {
        $this->assertSame('08:00', AlexaController::parseTime(null));
        $this->assertSame('07:30', AlexaController::parseTime('07:30'));
        $this->assertSame('08:00', AlexaController::parseTime('MO'));
        $this->assertSame('18:00', AlexaController::parseTime('EV'));
        $this->assertSame('08:00', AlexaController::parseTime('25:99'));
    }

    public function test_reminder_text_stays_short(): void
    {
        $rows = array_map(fn ($i) => ['date' => '2026-11-13', 'time' => '1'.($i % 10).':00', 'items' => "1x Produto com nome bem comprido número {$i}", 'customer' => 'Cliente '.$i, 'method' => 'local'], range(1, 9));

        $text = (new AlexaSpeech)->dailyReminder($rows, 2);

        $this->assertLessThanOrEqual(AlexaSpeech::REMINDER_BUDGET + 60, mb_strlen($text));
        $this->assertStringStartsWith('Bom dia! Hoje você tem 9 entregas.', $text);
        $this->assertStringContainsString('Pergunte ao Meu Custo Total para ouvir todas.', $text);
        $this->assertStringEndsWith('E 2 entregas atrasadas.', $text);
    }

    private function reminder(): AlexaReminder
    {
        return AlexaReminder::create([
            'user_id' => $this->user->id, 'alexa_user_id' => self::ALEXA_USER, 'api_endpoint' => self::API,
            'alert_token' => 'tok-1', 'remind_at' => '08:00',
        ]);
    }

    private function ask(string $intent, bool $granted = true, array $slots = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/alexa', [
            'version' => '1.0',
            'context' => ['System' => [
                'application' => ['applicationId' => 'x'],
                'apiEndpoint' => self::API,
                'apiAccessToken' => 'api-token',
                'user' => [
                    'userId' => self::ALEXA_USER,
                    'accessToken' => $this->user->createToken('alexa')->plainTextToken,
                    'permissions' => $granted
                        ? ['consentToken' => 'consent', 'scopes' => ['alexa::alerts:reminders:skill:readwrite' => ['status' => 'GRANTED']]]
                        : [],
                ],
            ]],
            'request' => ['type' => 'IntentRequest', 'timestamp' => now()->toIso8601ZuluString(), 'intent' => ['name' => $intent, 'slots' => $slots]],
        ]);
    }

    private function message(array $data, string $alexaUser = self::ALEXA_USER)
    {
        return $this->postJson('/api/v1/alexa', [
            'version' => '1.0',
            'context' => ['System' => ['application' => ['applicationId' => 'x'], 'apiEndpoint' => self::API, 'apiAccessToken' => 'msg-api-token', 'user' => ['userId' => $alexaUser]]],
            'request' => ['type' => 'Messaging.MessageReceived', 'timestamp' => now()->toIso8601ZuluString(), 'message' => $data],
        ]);
    }
}
