<?php

namespace App\Services\Alexa;

use App\Support\Delivery;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * APIs da Alexa usadas no resumo diário:
 * - Lembretes: criar (só durante a conversa, com o apiAccessToken da requisição), alterar e apagar;
 * - Skill Messaging: acordar a skill fora da conversa — a Amazon devolve um Messaging.MessageReceived com um
 *   apiAccessToken que permite alterar o lembrete.
 */
class AlexaApi
{
    public const REMINDER_PERMISSION = 'alexa::alerts:reminders:skill:readwrite';

    private const LWA_URL = 'https://api.amazon.com/auth/o2/token';

    /** Cria o lembrete diário e devolve o alertToken dele. */
    public function createDailyReminder(string $endpoint, string $token, string $time, string $text): string
    {
        $response = $this->reminders($endpoint, $token)->post('/v1/alerts/reminders', $this->reminderBody($time, $text));
        $this->ensureOk($response, 'criar o lembrete');

        return (string) $response->json('alertToken');
    }

    /** Troca horário e texto. Devolve false se o lembrete não existe mais (a pessoa apagou no app da Alexa). */
    public function updateDailyReminder(string $endpoint, string $token, string $alertToken, string $time, string $text): bool
    {
        $response = $this->reminders($endpoint, $token)->put('/v1/alerts/reminders/'.rawurlencode($alertToken), $this->reminderBody($time, $text));
        if ($response->status() === 404) {
            return false;
        }
        $this->ensureOk($response, 'atualizar o lembrete');

        return true;
    }

    public function deleteReminder(string $endpoint, string $token, string $alertToken): void
    {
        $response = $this->reminders($endpoint, $token)->delete('/v1/alerts/reminders/'.rawurlencode($alertToken));
        if ($response->status() !== 404) {
            $this->ensureOk($response, 'apagar o lembrete');
        }
    }

    /** Acorda a skill fora da conversa pra esta pessoa (a resposta vem depois, como Messaging.MessageReceived). */
    public function sendSkillMessage(string $endpoint, string $alexaUserId, array $data): void
    {
        $response = Http::withToken($this->messagingToken())
            ->acceptJson()
            ->timeout(10)
            ->post(rtrim($endpoint, '/').'/v1/skillmessages/users/'.rawurlencode($alexaUserId), [
                'data' => $data,
                'expiresAfterSeconds' => 3600,
            ]);
        $this->ensureOk($response, 'enviar a mensagem para a skill');
    }

    public static function messagingConfigured(): bool
    {
        return (bool) config('services.alexa.messaging_client_id') && (bool) config('services.alexa.messaging_client_secret');
    }

    /**
     * Lembrete que repete todo dia no horário, no fuso de Brasília. Começa na próxima vez que o horário chegar.
     */
    public function reminderBody(string $time, string $text): array
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return [
            'requestTime' => now()->toIso8601ZuluString(),
            'trigger' => [
                'type' => 'SCHEDULED_ABSOLUTE',
                'timeZoneId' => Delivery::TZ,
                'recurrence' => [
                    'startDateTime' => self::nextOccurrence($time)->format('Y-m-d\TH:i:s.000'),
                    'recurrenceRules' => ["FREQ=DAILY;BYHOUR={$hour};BYMINUTE={$minute};BYSECOND=0;INTERVAL=1;"],
                ],
            ],
            'alertInfo' => ['spokenInfo' => ['content' => [['locale' => 'pt-BR', 'text' => $text]]]],
            'pushNotification' => ['status' => 'ENABLED'],
        ];
    }

    /** Próxima vez que o horário chega (hoje, se ainda não passou; senão amanhã). */
    public static function nextOccurrence(string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $at = now(Delivery::TZ)->setTime($hour, $minute);

        return $at->isPast() ? $at->addDay() : $at;
    }

    private function reminders(string $endpoint, string $token)
    {
        return Http::baseUrl(rtrim($endpoint, '/'))->withToken($token)->acceptJson()->timeout(10);
    }

    /** Token de app (Login with Amazon) pro Skill Messaging — vale ~1 h, guardado até perto de vencer. */
    private function messagingToken(): string
    {
        return Cache::remember('alexa-messaging-token', now()->addMinutes(50), function () {
            $response = Http::asForm()->timeout(10)->post(self::LWA_URL, [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.alexa.messaging_client_id'),
                'client_secret' => config('services.alexa.messaging_client_secret'),
                'scope' => 'alexa:skill_messaging',
            ]);
            $this->ensureOk($response, 'obter o token do Skill Messaging');

            return (string) $response->json('access_token');
        });
    }

    private function ensureOk(Response $response, string $action): void
    {
        if (! $response->successful()) {
            throw new RuntimeException("Alexa: não deu para {$action} (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 300));
        }
    }
}
