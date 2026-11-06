<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PlatformFeedback;
use App\Models\User;
use App\Notifications\PlatformFeedbackToAdmin;
use App\Support\Background;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Opinião dos lojistas sobre a plataforma.
 *
 * Quando perguntar (só o dono, nunca no "Entrar como"):
 *  - trial_exit: o teste grátis do Pro acabou há até 30 dias e a loja não assinou — "o que pesou?". Uma vez por loja
 *    (também pelo link do e-mail de fim do teste). Fura o limite de 30 dias do NPS: é outro momento.
 *  - nps: loja com 30+ dias, fora do teste, sem resposta de NPS nos últimos 90 dias e sem nenhuma resposta da loja
 *    nos últimos 30.
 *  "Agora não" adia 7 dias; na segunda vez seguida, 90 dias (users.feedback_state).
 *
 * Nota baixa (NPS 0–6), resposta com comentário ou fim do teste: avisa a plataforma por e-mail e WhatsApp.
 */
class PlatformFeedbackService
{
    public const NPS_MIN_ACCOUNT_DAYS = 30;

    public const NPS_INTERVAL_DAYS = 90;

    public const GLOBAL_GAP_DAYS = 30;

    public const TRIAL_EXIT_WINDOW_DAYS = 30;

    public const LINK_DAYS = 30;

    public function __construct(private PlatformWhatsApp $whatsapp) {}

    /** Pergunta devida agora pra esta pessoa, ou null. */
    public function due(User $user, ?Carbon $now = null): ?string
    {
        $now ??= now();
        $company = $user->company;
        if (! $user->isOwner() || ! $company || $company->blocked_at || $company->deletion_scheduled_for) {
            return null;
        }

        if ($this->trialExitDue($company, $now) && ! $this->snoozed($user, PlatformFeedback::KIND_TRIAL_EXIT, $now)) {
            return PlatformFeedback::KIND_TRIAL_EXIT;
        }

        if ($company->onProTrial() || $company->created_at->gt($now->copy()->subDays(self::NPS_MIN_ACCOUNT_DAYS))) {
            return null;
        }
        if ($this->snoozed($user, PlatformFeedback::KIND_NPS, $now)) {
            return null;
        }
        $lastNps = PlatformFeedback::where('user_id', $user->id)->where('kind', PlatformFeedback::KIND_NPS)->max('created_at');
        if ($lastNps && Carbon::parse($lastNps)->gt($now->copy()->subDays(self::NPS_INTERVAL_DAYS))) {
            return null;
        }
        if (PlatformFeedback::where('company_id', $company->id)->where('created_at', '>', $now->copy()->subDays(self::GLOBAL_GAP_DAYS))->exists()) {
            return null;
        }

        return PlatformFeedback::KIND_NPS;
    }

    /** Teste acabou há pouco, não assinou e ainda não disse por quê. */
    public function trialExitDue(Company $company, Carbon $now): bool
    {
        $end = $company->pro_trial_ends_at;
        if (! $company->hadProTrial() || ! $end || $end->isFuture() || $end->lt($now->copy()->subDays(self::TRIAL_EXIT_WINDOW_DAYS))) {
            return false;
        }

        return ! $company->subscribed('default')
            && ! PlatformFeedback::where('company_id', $company->id)->where('kind', PlatformFeedback::KIND_TRIAL_EXIT)->exists();
    }

    /** "Agora não" / fechou: 7 dias; na segunda vez seguida, 90. */
    public function dismiss(User $user, string $kind, ?Carbon $now = null): void
    {
        $now ??= now();
        $state = $user->feedback_state ?? [];
        $times = (int) ($state[$kind]['dismissals'] ?? 0) + 1;
        $state[$kind] = [
            'dismissals' => $times,
            'snooze_until' => $now->copy()->addDays($times >= 2 ? 90 : 7)->toIso8601String(),
        ];
        $user->forceFill(['feedback_state' => $state])->save();
    }

    /**
     * Grava a resposta. O fim do teste é um por loja: responder de novo (pelo e-mail depois do app, por exemplo)
     * atualiza a mesma linha.
     *
     * @param  array{kind: string, score?: ?int, reason?: ?string, comment?: ?string}  $data
     */
    public function record(Company $company, ?User $user, array $data, string $channel = 'app'): PlatformFeedback
    {
        $kind = $data['kind'];
        $comment = isset($data['comment']) ? (trim((string) $data['comment']) ?: null) : null;
        $values = [
            'user_id' => $user?->id,
            'score' => $kind === PlatformFeedback::KIND_NPS ? $data['score'] : null,
            'reason' => $kind === PlatformFeedback::KIND_TRIAL_EXIT ? $data['reason'] : null,
            'comment' => $comment,
            'channel' => $channel,
            'context' => $this->context($company, $user),
        ];

        $feedback = $kind === PlatformFeedback::KIND_TRIAL_EXIT
            ? PlatformFeedback::updateOrCreate(['company_id' => $company->id, 'kind' => $kind], $values)
            : PlatformFeedback::create(['company_id' => $company->id, 'kind' => $kind, ...$values]);

        if ($user) {
            $state = $user->feedback_state ?? [];
            unset($state[$kind]); // respondeu: zera os "agora não"
            $user->forceFill(['feedback_state' => $state ?: null])->save();
        }

        if ($this->deservesAlert($feedback)) {
            $id = $feedback->id;
            Background::run(static fn () => app(self::class)->sendAlert($id));
        }

        return $feedback;
    }

    public function deservesAlert(PlatformFeedback $feedback): bool
    {
        return $feedback->kind === PlatformFeedback::KIND_TRIAL_EXIT
            || $feedback->comment !== null
            || ($feedback->score !== null && $feedback->score <= 6);
    }

    /** Precisa de conversa (aparece no contador do menu do admin): fim do teste ou NPS 0–6, ainda sem retorno. */
    public static function pendingQuery()
    {
        return PlatformFeedback::query()
            ->whereNull('followed_up_at')
            ->where('created_at', '>=', now()->subDays(60))
            ->where(fn ($q) => $q->where('kind', PlatformFeedback::KIND_TRIAL_EXIT)
                ->orWhere(fn ($q) => $q->where('kind', PlatformFeedback::KIND_NPS)->where('score', '<=', 6)));
    }

    /** Roda em segundo plano: e-mail (PLATFORM_ADMIN_EMAILS) + WhatsApp (PLATFORM_SUPPORT_WHATSAPP). */
    public function sendAlert(int $feedbackId): void
    {
        $feedback = PlatformFeedback::with(['company:id,name,plan', 'user:id,name,email'])->find($feedbackId);
        if (! $feedback?->company) {
            return;
        }

        $emails = config('services.platform.admin_emails', []);
        if ($emails) {
            try {
                Notification::route('mail', $emails)->notify(new PlatformFeedbackToAdmin($feedback));
            } catch (Throwable $e) {
                Log::warning("Opinião #{$feedback->id}: e-mail pro admin não enviado: ".$e->getMessage());
            }
        }

        $this->whatsapp->sendToPlatformTeam($this->alertText($feedback));
    }

    public function alertText(PlatformFeedback $feedback): string
    {
        $shop = $feedback->company->name;
        $days = $feedback->context['days_since_signup'] ?? null;
        $title = $feedback->kind === PlatformFeedback::KIND_TRIAL_EXIT
            ? "🧪 *Teste acabou sem assinar* — {$shop}"
            : ($feedback->score <= 6 ? '🔴' : ($feedback->score <= 8 ? '🟡' : '🟢'))." *NPS {$feedback->score}/10* — {$shop}";

        return implode("\n", array_filter([
            $title,
            $feedback->reason ? 'Motivo: '.$feedback->reasonLabel() : null,
            $feedback->comment ? '"'.Str::limit($feedback->comment, 600).'"' : null,
            ($feedback->user?->name ?? 'Dono').($days !== null ? " · {$days} dias de conta" : '').($feedback->channel === 'email' ? ' · pelo e-mail' : ''),
            '',
            rtrim((string) config('services.frontend_url'), '/').'/admin/opinioes',
        ], fn ($line) => $line !== null));
    }

    /** Link do e-mail (sem login): /opiniao/{token}. Assinado com a APP_KEY, vale 30 dias. */
    public function linkToken(User $user, string $kind, ?Carbon $now = null): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'u' => $user->id,
            'k' => $kind,
            'e' => ($now ?? now())->copy()->addDays(self::LINK_DAYS)->timestamp,
        ])), '+/', '-_'), '=');

        return $payload.'.'.$this->sign($payload);
    }

    public function linkUrl(User $user, string $kind): string
    {
        return rtrim((string) config('services.frontend_url'), '/').'/opiniao/'.$this->linkToken($user, $kind);
    }

    /** @return array{user: User, kind: string}|null */
    public function parseToken(string $token): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || ! hash_equals($this->sign($payload), $signature)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (! is_array($data) || ($data['e'] ?? 0) < now()->timestamp || ! in_array($data['k'] ?? null, PlatformFeedback::KINDS, true)) {
            return null;
        }
        $user = User::with('company')->find($data['u'] ?? 0);

        return $user?->company ? ['user' => $user, 'kind' => $data['k']] : null;
    }

    private function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'platform-feedback|'.$payload, (string) config('app.key')), 0, 32);
    }

    private function snoozed(User $user, string $kind, Carbon $now): bool
    {
        $until = $user->feedback_state[$kind]['snooze_until'] ?? null;

        return $until && Carbon::parse($until)->gt($now);
    }

    /** Retrato da loja na hora da resposta (pra cruzar depois: plano, tempo de conta, origem do teste). */
    private function context(Company $company, ?User $user): array
    {
        return array_filter([
            'plan' => $company->plan,
            'subscribed' => $company->subscribed('default'),
            'days_since_signup' => (int) $company->created_at->diffInDays(now()),
            'trial_source' => $company->pro_trial_source,
            'segment' => $company->segment,
            'role' => $user?->role,
        ], fn ($v) => $v !== null);
    }
}
