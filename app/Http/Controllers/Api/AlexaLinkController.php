<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\AlexaReminder;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Alexa\AlexaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Ligar a conta na skill da Alexa (OAuth 2.0 "authorization code", o que a Amazon exige):
 * 1. a Amazon abre /alexa/autorizar no app; a pessoa logada permite e o app chama authorize → código de 5 min;
 * 2. a Amazon troca o código em token: token de acesso "alexa" (24 h) + refresh "alexa:refresh" pra renovar sozinha.
 * Os dois são tokens Sanctum que não abrem nenhuma rota do app (RestrictIntegrationTokens) — só servem na skill.
 */
class AlexaLinkController extends Controller
{
    use EnforcesPlanLimits;

    public const REFRESH_TOKEN_NAME = 'alexa:refresh';

    public const ACCESS_TTL_SECONDS = 86400;

    private const CODE_TTL_SECONDS = 300;

    /** Pessoa logada permitiu: devolve o endereço da Amazon com o código. */
    public function authorize(Request $request): JsonResponse
    {
        $this->requirePro($request, 'Alexa');
        // No "Entrar como" seria a Alexa do admin passando a ouvir as entregas da loja
        abort_if(AdminAudit::impersonatorId($request->user()->currentAccessToken()) !== null, 403, 'Você está dentro da loja pela área admin — a própria loja liga a Alexa.');

        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
            'redirect_uri' => ['required', 'string', 'max:2048'],
            'state' => ['required', 'string', 'max:2048'],
            'response_type' => ['sometimes', 'in:code'],
        ]);

        abort_unless($this->validClientId($data['client_id']), 422, 'Pedido de ligação inválido (cliente desconhecido).');
        abort_unless($this->allowedRedirect($data['redirect_uri']), 422, 'Pedido de ligação inválido (endereço de retorno desconhecido).');

        $code = Str::random(48);
        Cache::put($this->codeKey($code), ['user_id' => $request->user()->id, 'redirect_uri' => $data['redirect_uri']], self::CODE_TTL_SECONDS);

        return response()->json(['redirect' => $this->withQuery($data['redirect_uri'], ['state' => $data['state'], 'code' => $code])]);
    }

    /** Endpoint de token da Amazon (sem login): troca código ou refresh por um par novo de tokens. */
    public function token(Request $request): JsonResponse
    {
        [$clientId, $clientSecret] = $this->clientCredentials($request);
        if (! $this->validClientId($clientId) || ! $this->validClientSecret($clientSecret)) {
            return $this->oauthError('invalid_client', 401);
        }

        return match ($request->input('grant_type')) {
            'authorization_code' => $this->fromCode($request),
            'refresh_token' => $this->fromRefresh($request),
            default => $this->oauthError('unsupported_grant_type'),
        };
    }

    /** Card "Alexa" em Integrações: a pessoa logada já ligou? */
    public function status(Request $request): JsonResponse
    {
        $refresh = $request->user()->tokens()->where('name', self::REFRESH_TOKEN_NAME)->latest()->first();
        $access = $request->user()->tokens()->where('name', AlexaController::TOKEN_NAME)->latest('last_used_at')->first();

        return response()->json([
            // Card em Integrações: com a ligação configurada e liberada no admin (admins da plataforma sempre veem)
            'available' => (bool) config('services.alexa.client_id')
                && ($request->user()->isPlatformAdmin() || (bool) PlatformSetting::get(PlatformSetting::ALEXA_VISIBLE)),
            'linked' => (bool) $refresh,
            'linked_at' => $refresh?->created_at?->toIso8601String(),
            'last_used_at' => $access?->last_used_at?->toIso8601String(),
            'daily_summary_at' => AlexaReminder::where('user_id', $request->user()->id)->value('remind_at'),
        ]);
    }

    /** Desligar: a Alexa perde o acesso na hora (na próxima pergunta pede pra ligar de novo). */
    public function unlink(Request $request, AlexaApi $api): JsonResponse
    {
        $request->user()->tokens()->whereIn('name', [AlexaController::TOKEN_NAME, self::REFRESH_TOKEN_NAME])->delete();

        // Resumo diário: pede à Amazon pra apagar o lembrete do aparelho (sem isso ele seguiria tocando com texto velho)
        $reminder = AlexaReminder::where('user_id', $request->user()->id)->first();
        if ($reminder) {
            if (AlexaApi::messagingConfigured()) {
                try {
                    $api->sendSkillMessage($reminder->api_endpoint, $reminder->alexa_user_id, ['type' => 'remove', 'alert_token' => $reminder->alert_token]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            $reminder->delete();
        }

        return response()->json(null, 204);
    }

    private function fromCode(Request $request): JsonResponse
    {
        $code = (string) $request->input('code');
        $grant = $code !== '' ? Cache::pull($this->codeKey($code)) : null;
        if (! $grant || ($request->filled('redirect_uri') && $request->input('redirect_uri') !== $grant['redirect_uri'])) {
            return $this->oauthError('invalid_grant');
        }

        $user = User::find($grant['user_id']);
        if (! $user) {
            return $this->oauthError('invalid_grant');
        }

        // Ligar de novo substitui a ligação anterior (um par de tokens por pessoa)
        $user->tokens()->whereIn('name', [AlexaController::TOKEN_NAME, self::REFRESH_TOKEN_NAME])->delete();

        return $this->issue($user);
    }

    private function fromRefresh(Request $request): JsonResponse
    {
        $refresh = PersonalAccessToken::findToken((string) $request->input('refresh_token'));
        if (! $refresh || $refresh->name !== self::REFRESH_TOKEN_NAME || ! $refresh->tokenable instanceof User) {
            // invalid_grant faz a Alexa apagar a ligação — é o caso: refresh desconhecido ou desligado no app
            return $this->oauthError('invalid_grant');
        }

        $user = $refresh->tokenable;
        $refresh->delete();
        $user->tokens()->where('name', AlexaController::TOKEN_NAME)->delete();

        return $this->issue($user);
    }

    private function issue(User $user): JsonResponse
    {
        $access = $user->createToken(AlexaController::TOKEN_NAME, ['*'], now()->addSeconds(self::ACCESS_TTL_SECONDS));
        $refresh = $user->createToken(self::REFRESH_TOKEN_NAME);

        return response()->json([
            'access_token' => $access->plainTextToken,
            'token_type' => 'bearer',
            'expires_in' => self::ACCESS_TTL_SECONDS,
            'refresh_token' => $refresh->plainTextToken,
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /** A Amazon manda as credenciais por HTTP Basic ou no corpo, conforme a opção escolhida no console. */
    private function clientCredentials(Request $request): array
    {
        if ($request->getUser() !== null) {
            return [urldecode((string) $request->getUser()), urldecode((string) $request->getPassword())];
        }

        return [(string) $request->input('client_id'), (string) $request->input('client_secret')];
    }

    private function validClientId(?string $clientId): bool
    {
        $expected = (string) config('services.alexa.client_id');

        return $expected !== '' && hash_equals($expected, (string) $clientId);
    }

    private function validClientSecret(?string $secret): bool
    {
        $expected = (string) config('services.alexa.client_secret');

        return $expected !== '' && hash_equals($expected, (string) $secret);
    }

    /** Só os endereços de retorno que o console da Amazon mostra pra skill (ALEXA_REDIRECT_URIS). */
    private function allowedRedirect(string $uri): bool
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) config('services.alexa.redirect_uris'))));

        return in_array($uri, $allowed, true);
    }

    private function withQuery(string $uri, array $params): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params);
    }

    private function codeKey(string $code): string
    {
        return 'alexa-code:'.hash('sha256', $code);
    }

    private function oauthError(string $error, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $error], $status)->header('Cache-Control', 'no-store');
    }
}
