<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlexaReminder;
use App\Models\Company;
use App\Models\User;
use App\Services\Alexa\AlexaApi;
use App\Services\Alexa\AlexaRequestVerifier;
use App\Services\Alexa\AlexaSpeech;
use App\Services\DeliveryAgenda;
use App\Support\Delivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Skill "Meu Custo Total" da Alexa (Pro): responde em voz quais são as entregas. A loja vem do token que a Alexa
 * manda (conta ligada no app da Alexa, token Sanctum "alexa" — que não abre nenhuma outra rota, ver
 * RestrictIntegrationTokens). Nunca fala valores.
 */
class AlexaController extends Controller
{
    public const TOKEN_NAME = 'alexa';

    private const HELP = 'Você pode perguntar: o que tenho pra entregar hoje, quais as entregas de amanhã, qual a próxima entrega, ou se tem entrega atrasada. E pode pedir: me avise das entregas todo dia às 8.';

    /** Períodos que a Alexa entende como horário ("de manhã" = MO...) → hora do lembrete */
    private const TIME_PERIODS = ['MO' => '08:00', 'AF' => '13:00', 'EV' => '18:00', 'NI' => '20:00'];

    public function __construct(
        private readonly AlexaRequestVerifier $verifier,
        private readonly DeliveryAgenda $agenda,
        private readonly AlexaSpeech $speech,
        private readonly AlexaApi $api,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $failure = $this->verifier->failure($body, $request->header('SignatureCertChainUrl'), $request->header('Signature-256'));
        if ($failure) {
            Log::warning('Alexa: requisição recusada', ['motivo' => $failure, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Requisição inválida.'], 400);
        }

        $payload = json_decode($body, true);
        $type = data_get($payload, 'request.type');

        if ($type === 'SessionEndedRequest') {
            return response()->json(['version' => '1.0', 'response' => new \stdClass]);
        }

        // Mensagem que o próprio servidor mandou pela Amazon (alexa:daily-summaries): atualiza o lembrete diário
        if ($type === 'Messaging.MessageReceived') {
            $this->handleMessage($payload);

            return response()->json(['version' => '1.0', 'response' => new \stdClass]);
        }

        $user = $this->resolveUser($payload);
        if (! $user) {
            Log::info('Alexa: conta não ligada', ['alexa_user' => data_get($payload, 'context.System.user.userId')]);

            return $this->linkAccount();
        }

        $company = $user->company;
        if ($blocked = $this->blockedMessage($company)) {
            return $this->say($blocked);
        }

        if ($type === 'LaunchRequest') {
            return $this->say('Olá! '.self::HELP, ask: true);
        }
        if ($type !== 'IntentRequest') {
            return $this->say('Não entendi. '.self::HELP, ask: true);
        }

        try {
            return match (data_get($payload, 'request.intent.name')) {
                'EntregasPorDataIntent' => $this->deliveriesFor($company, data_get($payload, 'request.intent.slots.data.value')),
                'ProximaEntregaIntent' => $this->nextDelivery($company),
                'EntregasAtrasadasIntent' => $this->overdueDeliveries($company),
                'LembreteDiarioIntent' => $this->enableDailySummary($user, $payload),
                'CancelarLembreteDiarioIntent' => $this->disableDailySummary($user, $payload),
                'AMAZON.HelpIntent' => $this->say(self::HELP, ask: true),
                'AMAZON.StopIntent', 'AMAZON.CancelIntent', 'AMAZON.NavigateHomeIntent' => $this->say('Até mais!'),
                default => $this->say('Não entendi. '.self::HELP, ask: true),
            };
        } catch (\Throwable $e) {
            // Sem isto a Alexa fala só "houve um problema com a resposta da skill"
            report($e);

            return $this->say('Não consegui consultar as suas entregas agora. Tente de novo em instantes.');
        }
    }

    private function deliveriesFor(Company $company, ?string $value): JsonResponse
    {
        [$from, $to] = self::parseDate($value);
        $rows = $this->agenda->between($company, $from, $to)->map(fn ($q) => AlexaSpeech::row($q))->all();
        // Atrasadas só entram no aviso quando a pergunta é sobre hoje
        $overdue = $from->isSameDay(now(Delivery::TZ)) && $from->isSameDay($to) ? $this->agenda->overdue($company)->count() : 0;

        $result = $this->speech->period($from, $to, $rows, $overdue);

        return $this->say($result['speech'], 'Entregas', $result['card']);
    }

    private function nextDelivery(Company $company): JsonResponse
    {
        $next = $this->agenda->next($company);
        $result = $this->speech->next($next ? AlexaSpeech::row($next) : null);

        return $this->say($result['speech'], 'Próxima entrega', $result['card']);
    }

    private function overdueDeliveries(Company $company): JsonResponse
    {
        $rows = $this->agenda->overdue($company)->map(fn ($q) => AlexaSpeech::row($q))->all();
        $result = $this->speech->overdue($rows);

        return $this->say($result['speech'], 'Entregas atrasadas', $result['card']);
    }

    /**
     * "Me avise das entregas todo dia às 8": cria (ou muda o horário de) um lembrete diário. A Amazon só deixa criar
     * lembrete durante a conversa, com permissão dada no app da Alexa; o texto é refeito de hora em hora.
     */
    private function enableDailySummary(User $user, array $payload): JsonResponse
    {
        $token = data_get($payload, 'context.System.apiAccessToken');
        $endpoint = data_get($payload, 'context.System.apiEndpoint');
        if (! $token || ! $endpoint || ! self::remindersGranted($payload)) {
            return $this->askReminderPermission();
        }

        $time = self::parseTime(data_get($payload, 'request.intent.slots.hora.value'));
        $text = $this->dailySummaryText($user->company, $time);
        $existing = AlexaReminder::where('user_id', $user->id)->first();

        try {
            $updated = $existing && $this->api->updateDailyReminder($endpoint, $token, $existing->alert_token, $time, $text);
            $alertToken = $updated ? $existing->alert_token : $this->api->createDailyReminder($endpoint, $token, $time, $text);
        } catch (\Throwable $e) {
            report($e);

            return $this->say('Não consegui criar o lembrete agora. Tente de novo daqui a pouco.');
        }

        AlexaReminder::updateOrCreate(['user_id' => $user->id], [
            'alexa_user_id' => (string) data_get($payload, 'context.System.user.userId'),
            'api_endpoint' => $endpoint,
            'alert_token' => $alertToken,
            'remind_at' => $time,
            'last_synced_at' => now(),
        ]);

        return $this->say('Combinado! Todo dia '.self::timeLabel($time).' eu te digo as entregas do dia. Para parar, é só pedir: desative o resumo diário.');
    }

    private function disableDailySummary(User $user, array $payload): JsonResponse
    {
        $reminder = AlexaReminder::where('user_id', $user->id)->first();
        if (! $reminder) {
            return $this->say('O resumo diário não está ativado.');
        }

        $token = data_get($payload, 'context.System.apiAccessToken');
        $endpoint = data_get($payload, 'context.System.apiEndpoint');
        try {
            if ($token && $endpoint) {
                $this->api->deleteReminder($endpoint, $token, $reminder->alert_token);
            }
        } catch (\Throwable $e) {
            // Sem permissão (ou lembrete já apagado no app): some do nosso lado mesmo assim
            report($e);
        }
        $reminder->delete();

        return $this->say('Pronto, desativei o resumo diário.');
    }

    /** Mensagem de hora em hora: refaz o texto do lembrete com as entregas do dia (ou apaga, ao desligar a Alexa). */
    private function handleMessage(array $payload): void
    {
        $message = (array) data_get($payload, 'request.message', []);
        $token = data_get($payload, 'context.System.apiAccessToken');
        $endpoint = data_get($payload, 'context.System.apiEndpoint');
        if (! $token || ! $endpoint) {
            return;
        }

        try {
            if (($message['type'] ?? null) === 'remove' && ! empty($message['alert_token'])) {
                $this->api->deleteReminder($endpoint, $token, (string) $message['alert_token']);

                return;
            }

            $reminder = AlexaReminder::with('user.company')->find($message['reminder_id'] ?? 0);
            if (! $reminder || $reminder->alexa_user_id !== data_get($payload, 'context.System.user.userId')) {
                return;
            }

            $company = $reminder->user?->company;
            $text = $company && ! $this->blockedMessage($company)
                ? $this->dailySummaryText($company, $reminder->remind_at)
                : 'O resumo das entregas do Meu Custo Total está pausado. Abra o app para ver o que aconteceu com a conta.';

            if ($this->api->updateDailyReminder($endpoint, $token, $reminder->alert_token, $reminder->remind_at, $text)) {
                $reminder->update(['last_synced_at' => now()]);
            } else {
                // A pessoa apagou o lembrete no app da Alexa
                $reminder->delete();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Texto do lembrete para o dia em que ele vai tocar de novo. */
    private function dailySummaryText(Company $company, string $time): string
    {
        $day = AlexaApi::nextOccurrence($time)->startOfDay();
        $rows = $this->agenda->between($company, $day, $day->copy())->map(fn ($q) => AlexaSpeech::row($q))->all();

        return $this->speech->dailyReminder($rows, $this->agenda->overdue($company)->count());
    }

    private static function remindersGranted(array $payload): bool
    {
        $permissions = (array) data_get($payload, 'context.System.user.permissions', []);
        if (isset($permissions['scopes'])) {
            return ($permissions['scopes'][AlexaApi::REMINDER_PERMISSION]['status'] ?? null) === 'GRANTED';
        }

        // Formato antigo: sem "scopes", o consentToken só vem quando alguma permissão foi dada
        return ! empty($permissions['consentToken']);
    }

    private function askReminderPermission(): JsonResponse
    {
        return response()->json([
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => 'Para eu te avisar todo dia, preciso da permissão de lembretes. Mandei um cartão no app da Alexa: toque em permitir e me peça de novo.'],
                'card' => ['type' => 'AskForPermissionsConsent', 'permissions' => [AlexaApi::REMINDER_PERMISSION]],
                'shouldEndSession' => true,
            ],
        ]);
    }

    /** Slot AMAZON.TIME ("08:00", "MO" de manhã...) → "HH:MM"; sem horário, 8h. */
    public static function parseTime(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        if (isset(self::TIME_PERIODS[$value])) {
            return self::TIME_PERIODS[$value];
        }
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
            return "{$m[1]}:{$m[2]}";
        }

        return '08:00';
    }

    private static function timeLabel(string $time): string
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        $article = $h === 1 ? 'à' : 'às';

        return $m === 0 ? "{$article} {$h} ".($h === 1 ? 'hora' : 'horas') : "{$article} {$h} e ".str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Valor do slot AMAZON.DATE → período. Aceita dia (2026-11-20), semana (2026-W47), fim de semana
     * (2026-W47-WE) e mês (2026-11); vazio ou outro formato vira hoje.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function parseDate(?string $value): array
    {
        $today = now(Delivery::TZ)->startOfDay();
        $value = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $day = Carbon::parse($value, Delivery::TZ)->startOfDay();

            return [$day, $day->copy()];
        }
        if (preg_match('/^(\d{4})-W(\d{1,2})(-WE)?$/', $value, $m)) {
            $monday = Carbon::now(Delivery::TZ)->setISODate((int) $m[1], (int) $m[2])->startOfDay();

            return isset($m[3]) ? [$monday->copy()->addDays(5), $monday->copy()->addDays(6)] : [$monday, $monday->copy()->addDays(6)];
        }
        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            $first = Carbon::parse($value.'-01', Delivery::TZ)->startOfDay();

            return [$first, $first->copy()->endOfMonth()->startOfDay()];
        }

        return [$today, $today->copy()];
    }

    private function resolveUser(array $payload): ?User
    {
        $token = data_get($payload, 'context.System.user.accessToken') ?? data_get($payload, 'session.user.accessToken');
        if ($token) {
            $access = PersonalAccessToken::findToken($token);
            $valid = $access && $access->name === self::TOKEN_NAME && (! $access->expires_at || $access->expires_at->isFuture());
            if ($valid && $access->tokenable instanceof User) {
                $access->forceFill(['last_used_at' => now()])->save();

                return $access->tokenable;
            }

            return null;
        }

        // Teste antes do login da loja existir (ver config/services.php)
        $devUser = config('services.alexa.dev_user_id');
        $devEmail = config('services.alexa.dev_email');
        if ($devUser && $devEmail && data_get($payload, 'context.System.user.userId') === $devUser) {
            return User::where('email', $devEmail)->first();
        }

        return null;
    }

    private function blockedMessage(?Company $company): ?string
    {
        if (! $company || $company->isBlocked()) {
            return 'O acesso desta conta está suspenso. Fale com o suporte do Meu Custo Total.';
        }
        if ($company->isPendingDeletion()) {
            return 'Esta conta está com a exclusão agendada.';
        }
        if (! $company->isPro()) {
            return 'Consultar as entregas pela Alexa é um recurso do plano Pro do Meu Custo Total.';
        }

        return null;
    }

    private function say(string $speech, ?string $cardTitle = null, ?string $cardText = null, bool $ask = false): JsonResponse
    {
        $response = [
            'outputSpeech' => ['type' => 'PlainText', 'text' => $speech],
            'shouldEndSession' => ! $ask,
        ];
        if ($ask) {
            $response['reprompt'] = ['outputSpeech' => ['type' => 'PlainText', 'text' => 'O que você quer saber das suas entregas?']];
        }
        if ($cardTitle && $cardText) {
            $response['card'] = ['type' => 'Simple', 'title' => $cardTitle, 'content' => $cardText];
        }

        return response()->json(['version' => '1.0', 'response' => $response]);
    }

    private function linkAccount(): JsonResponse
    {
        return response()->json([
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => 'Para ouvir suas entregas, ligue sua conta do Meu Custo Total no app da Alexa. Mandei um cartão lá com o atalho.'],
                'card' => ['type' => 'LinkAccount'],
                'shouldEndSession' => true,
            ],
        ]);
    }
}
