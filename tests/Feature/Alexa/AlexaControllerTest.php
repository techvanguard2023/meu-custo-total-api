<?php

namespace Tests\Feature\Alexa;

use App\Http\Controllers\Api\AlexaController;
use App\Services\Alexa\AlexaRequestVerifier;
use App\Support\Delivery;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AlexaControllerTest extends TestCase
{
    public function test_unsigned_request_is_rejected(): void
    {
        config(['services.alexa.skill_id' => 'amzn1.ask.skill.teste']);

        $this->postJson('/api/v1/alexa', $this->payload('LaunchRequest'))->assertStatus(400);
    }

    public function test_unlinked_account_gets_link_card(): void
    {
        $this->trustEveryRequest();

        $this->postJson('/api/v1/alexa', $this->payload('LaunchRequest'))
            ->assertOk()
            ->assertJsonPath('response.card.type', 'LinkAccount')
            ->assertJsonPath('response.shouldEndSession', true);
    }

    public function test_session_ended_returns_empty_response(): void
    {
        $this->trustEveryRequest();

        $this->postJson('/api/v1/alexa', $this->payload('SessionEndedRequest'))
            ->assertOk()
            ->assertJsonPath('version', '1.0');
    }

    public function test_parse_date_slot(): void
    {
        $this->travelTo(Carbon::parse('2026-11-13 09:00', Delivery::TZ));

        $range = fn ($value) => array_map(fn (Carbon $d) => $d->toDateString(), AlexaController::parseDate($value));

        $this->assertSame(['2026-11-13', '2026-11-13'], $range(null));
        $this->assertSame(['2026-11-20', '2026-11-20'], $range('2026-11-20'));
        $this->assertSame(['2026-11-16', '2026-11-22'], $range('2026-W47'));
        $this->assertSame(['2026-11-21', '2026-11-22'], $range('2026-W47-WE'));
        $this->assertSame(['2026-12-01', '2026-12-31'], $range('2026-12'));
        $this->assertSame(['2026-11-13', '2026-11-13'], $range('PRESENT_REF'));
    }

    private function trustEveryRequest(): void
    {
        $this->app->instance(AlexaRequestVerifier::class, new class extends AlexaRequestVerifier
        {
            public function failure(string $body, ?string $certUrl, ?string $signature): ?string
            {
                return null;
            }
        });
    }

    private function payload(string $type): array
    {
        return [
            'version' => '1.0',
            'context' => ['System' => ['application' => ['applicationId' => 'amzn1.ask.skill.teste'], 'user' => ['userId' => 'amzn1.ask.account.TESTE']]],
            'request' => ['type' => $type, 'timestamp' => now()->toIso8601ZuluString()],
        ];
    }
}
