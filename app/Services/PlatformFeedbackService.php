<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PlatformFeedback;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\PlatformFeedbackToAdmin;
use App\Support\Background;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Throwable;

/**
 * Opinião dos lojistas sobre a plataforma. Qual pergunta o card do app mostra, nesta ordem (nunca no "Entrar como"):
 *  - support_csat (qualquer perfil): chamado que a pessoa abriu, resolvido há até 14 dias e sem nota — "como foi o
 *    atendimento?" (1–5). Fechar o card não pergunta mais desse chamado; a tela do chamado continua deixando avaliar.
 *  - cancel_exit (dono): cancelou a assinatura (no app ou no portal da Stripe) há até 30 dias e não disse por quê.
 *    No app o motivo já é perguntado na hora de cancelar; quem pulou ali não é perguntado de novo.
 *  - trial_exit (dono): o teste grátis do Pro acabou há até 30 dias e a loja não assinou — "o que pesou?". Uma vez
 *    por loja (também pelo link do e-mail de fim do teste).
 *  - ces (dono): "foi fácil?" depois de cada primeiro passo (produto, orçamento, WhatsApp), em lojas com até 60 dias;
 *    um passo de cada vez, com 2 dias de intervalo. Fechar = não pergunta mais daquele passo.
 *  - nps (dono): loja com 30+ dias, fora do teste, sem NPS nos últimos 90 dias e sem resposta da loja nos últimos 30.
 *  "Agora não" adia 7 dias; na segunda vez seguida, 90 dias (users.feedback_state).
 *
 * Avisa a plataforma (e-mail + WhatsApp) quando a resposta pede conversa (fim do teste, cancelamento, NPS 0–6,
 * atendimento 1–2) ou tem comentário.
 */
class PlatformFeedbackService
{
    public const NPS_MIN_ACCOUNT_DAYS = 30;

    public const NPS_INTERVAL_DAYS = 90;

    public const GLOBAL_GAP_DAYS = 30;

    public const TRIAL_EXIT_WINDOW_DAYS = 30;

    public const CANCEL_EXIT_WINDOW_DAYS = 30;

    public const SUPPORT_CSAT_WINDOW_DAYS = 14;

    public const CES_MAX_ACCOUNT_DAYS = 60;

    public const CES_GAP_DAYS = 2;

    public const LINK_DAYS = 30;

    public function __construct(private PlatformWhatsApp $whatsapp) {}

    /**
     * Pergunta que o card do app mostra agora, ou null.
     *
     * @return array{kind: string, ticket?: array{id: int, subject: string}, milestone?: string}|null
     */
    public function prompt(User $user, ?Carbon $now = null): ?array
    {
        $now ??= now();
        $company = $user->company;
        if (! $company || $company->blocked_at || $company->deletion_scheduled_for) {
            return null;
        }

        if ($ticket = $this->ticketToRate($user, $now)) {
            return ['kind' => PlatformFeedback::KIND_SUPPORT_CSAT, 'ticket' => ['id' => $ticket->id, 'subject' => $ticket->subject]];
        }

        if (! $user->isOwner()) {
            return null;
        }

        if ($this->cancelExitSubscription($company, $now) && ! $this->snoozed($user, PlatformFeedback::KIND_CANCEL_EXIT, $now)) {
            return ['kind' => PlatformFeedback::KIND_CANCEL_EXIT];
        }

        if ($this->trialExitDue($company, $now) && ! $this->snoozed($user, PlatformFeedback::KIND_TRIAL_EXIT, $now)) {
            return ['kind' => PlatformFeedback::KIND_TRIAL_EXIT];
        }

        if ($milestone = $this->cesMilestone($user, $company, $now)) {
            return ['kind' => PlatformFeedback::KIND_CES, 'milestone' => $milestone];
        }

        return $this->npsDue($user, $company, $now) ? ['kind' => PlatformFeedback::KIND_NPS] : null;
    }

    /** Só o tipo da pergunta devida (ou null). */
    public function due(User $user, ?Carbon $now = null): ?string
    {
        return $this->prompt($user, $now)['kind'] ?? null;
    }

    private function npsDue(User $user, Company $company, Carbon $now): bool
    {
        if ($company->onProTrial() || $company->created_at->gt($now->copy()->subDays(self::NPS_MIN_ACCOUNT_DAYS))) {
            return false;
        }
        if ($this->snoozed($user, PlatformFeedback::KIND_NPS, $now)) {
            return false;
        }
        // Cancelou a assinatura (no período pago ou há até 30 dias): não é hora de perguntar se indicaria
        $subscription = $company->subscription('default');
        if ($subscription?->ends_at && $subscription->ends_at->gt($now->copy()->subDays(self::CANCEL_EXIT_WINDOW_DAYS))) {
            return false;
        }
        $lastNps = PlatformFeedback::where('user_id', $user->id)->where('kind', PlatformFeedback::KIND_NPS)->max('created_at');
        if ($lastNps && Carbon::parse($lastNps)->gt($now->copy()->subDays(self::NPS_INTERVAL_DAYS))) {
            return false;
        }

        // Respondeu outra coisa há pouco (fim do teste, cancelamento): espera. Avaliação de chamado e "foi fácil?" não contam.
        return ! PlatformFeedback::where('company_id', $company->id)
            ->whereNotIn('kind', [PlatformFeedback::KIND_SUPPORT_CSAT, PlatformFeedback::KIND_CES])
            ->where('created_at', '>', $now->copy()->subDays(self::GLOBAL_GAP_DAYS))
            ->exists();
    }

    /** Primeiro passo já feito que ainda não perguntou "foi fácil?" (loja nova; um de cada vez, com intervalo). */
    public function cesMilestone(User $user, Company $company, Carbon $now): ?string
    {
        if ($company->created_at->lt($now->copy()->subDays(self::CES_MAX_ACCOUNT_DAYS))) {
            return null;
        }
        $state = $user->feedback_state[PlatformFeedback::KIND_CES] ?? [];
        if (! empty($state['last_at']) && Carbon::parse($state['last_at'])->gt($now->copy()->subDays(self::CES_GAP_DAYS))) {
            return null;
        }
        $skipped = $state['skipped'] ?? [];
        $answered = PlatformFeedback::where('company_id', $company->id)->where('kind', PlatformFeedback::KIND_CES)->pluck('reason')->all();

        foreach (array_keys(PlatformFeedback::CES_MILESTONES) as $milestone) {
            if (in_array($milestone, $skipped, true) || in_array($milestone, $answered, true)) {
                continue;
            }
            $done = match ($milestone) {
                'products' => $company->products()->exists(),
                'quotes' => $company->quotes()->exists(),
                'whatsapp' => $company->whatsapp_session_name !== null,
                default => false,
            };
            if ($done) {
                return $milestone;
            }
        }

        return null;
    }

    /** Chamado que a pessoa abriu, resolvido há pouco, sem nota e que ela não dispensou no card. */
    public function ticketToRate(User $user, Carbon $now): ?SupportTicket
    {
        $dismissed = $user->feedback_state[PlatformFeedback::KIND_SUPPORT_CSAT]['tickets'] ?? [];

        return SupportTicket::where('user_id', $user->id)
            ->where('status', SupportTicket::STATUS_RESOLVED)
            ->where('resolved_at', '>=', $now->copy()->subDays(self::SUPPORT_CSAT_WINDOW_DAYS))
            ->whereDoesntHave('rating')
            ->when($dismissed, fn ($q) => $q->whereNotIn('id', $dismissed))
            ->latest('resolved_at')
            ->first(['id', 'subject']);
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

    /** Assinatura cancelada há até 30 dias (ainda no período pago ou já encerrada) cujo motivo a loja não disse. */
    public function cancelExitSubscription(Company $company, Carbon $now): ?Subscription
    {
        $subscription = $company->subscription('default');
        if (! $subscription?->ends_at || $subscription->ends_at->lt($now->copy()->subDays(self::CANCEL_EXIT_WINDOW_DAYS))) {
            return null;
        }
        // Cancelada de propósito: no período pago (cancelamento agendado) ou encerrada pela Stripe como "canceled"
        if (! $subscription->onGracePeriod() && $subscription->stripe_status !== 'canceled') {
            return null;
        }

        return $this->cancelFeedback($company, $subscription->stripe_id) ? null : $subscription;
    }

    private function cancelFeedback(Company $company, string $subscriptionId): ?PlatformFeedback
    {
        return PlatformFeedback::where('company_id', $company->id)
            ->where('kind', PlatformFeedback::KIND_CANCEL_EXIT)
            ->where('context->subscription_id', $subscriptionId)
            ->first();
    }

    /** "Agora não" / fechou: 7 dias; na segunda vez seguida, 90. No chamado, não pergunta mais daquele chamado. */
    public function dismiss(User $user, string $kind, ?int $ticketId = null, ?Carbon $now = null, ?string $milestone = null): void
    {
        $now ??= now();
        $state = $user->feedback_state ?? [];

        if ($kind === PlatformFeedback::KIND_CES) {
            $skipped = $state[$kind]['skipped'] ?? [];
            if ($milestone && isset(PlatformFeedback::CES_MILESTONES[$milestone])) {
                $skipped[] = $milestone;
            }
            $state[$kind] = ['skipped' => array_values(array_unique($skipped)), 'last_at' => $now->toIso8601String()];
        } elseif ($kind === PlatformFeedback::KIND_SUPPORT_CSAT) {
            if ($ticketId) {
                $tickets = $state[$kind]['tickets'] ?? [];
                $tickets[] = $ticketId;
                $state[$kind] = ['tickets' => array_values(array_slice(array_unique($tickets), -30))];
            }
        } else {
            $times = (int) ($state[$kind]['dismissals'] ?? 0) + 1;
            $state[$kind] = [
                'dismissals' => $times,
                'snooze_until' => $now->copy()->addDays($times >= 2 ? 90 : 7)->toIso8601String(),
            ];
        }
        $user->forceFill(['feedback_state' => $state])->save();
    }

    /** Não perguntar este tipo por N dias (ex: pulou o motivo na hora de cancelar). */
    public function snooze(User $user, string $kind, int $days): void
    {
        $state = $user->feedback_state ?? [];
        $state[$kind] = ['dismissals' => 2, 'snooze_until' => now()->addDays($days)->toIso8601String()];
        $user->forceFill(['feedback_state' => $state])->save();
    }

    /**
     * Grava a resposta. Uma por loja no fim do teste, uma por chamado na avaliação e uma por assinatura no
     * cancelamento: responder de novo atualiza a mesma linha.
     *
     * @param  array{kind: string, score?: ?int, reason?: ?string, comment?: ?string}  $data
     * @param  array{ticket?: SupportTicket, subscription_id?: string}  $about
     */
    public function record(Company $company, ?User $user, array $data, string $channel = 'app', array $about = []): PlatformFeedback
    {
        $kind = $data['kind'];
        $comment = isset($data['comment']) ? (trim((string) $data['comment']) ?: null) : null;
        $ticket = $about['ticket'] ?? null;
        $subscriptionId = $about['subscription_id'] ?? null;

        $values = [
            'user_id' => $user?->id,
            'score' => PlatformFeedback::scoreRange($kind) ? $data['score'] : null,
            'reason' => PlatformFeedback::reasonsFor($kind) ? $data['reason'] : null,
            'comment' => $comment,
            'channel' => $channel,
            'context' => $this->context($company, $user, array_filter([
                'subscription_id' => $subscriptionId,
                'ticket_category' => $ticket?->category,
            ])),
        ];

        $existing = match ($kind) {
            PlatformFeedback::KIND_TRIAL_EXIT => PlatformFeedback::where('company_id', $company->id)->where('kind', $kind)->first(),
            PlatformFeedback::KIND_SUPPORT_CSAT => $ticket ? PlatformFeedback::where('support_ticket_id', $ticket->id)->first() : null,
            PlatformFeedback::KIND_CANCEL_EXIT => $subscriptionId ? $this->cancelFeedback($company, $subscriptionId) : null,
            PlatformFeedback::KIND_CES => PlatformFeedback::where('company_id', $company->id)->where('kind', $kind)->where('reason', $data['reason'])->first(),
            default => null,
        };

        if ($existing) {
            $existing->update($values);
            $feedback = $existing;
        } else {
            $feedback = PlatformFeedback::create([
                'company_id' => $company->id,
                'kind' => $kind,
                'support_ticket_id' => $ticket?->id,
                ...$values,
            ]);
        }

        if ($user && $kind === PlatformFeedback::KIND_CES) {
            // Próximo "foi fácil?" só depois do intervalo
            $state = $user->feedback_state ?? [];
            $state[$kind] = [...($state[$kind] ?? []), 'last_at' => now()->toIso8601String()];
            $user->forceFill(['feedback_state' => $state])->save();
        } elseif ($user && $kind !== PlatformFeedback::KIND_SUPPORT_CSAT) {
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
        return $feedback->isNegative() || $feedback->comment !== null;
    }

    /** Precisa de conversa (contador do menu do admin): resposta negativa dos últimos 60 dias, ainda sem retorno. */
    public static function pendingQuery()
    {
        return PlatformFeedback::query()
            ->whereNull('followed_up_at')
            ->where('created_at', '>=', now()->subDays(60))
            ->where(fn ($q) => $q->whereIn('kind', [PlatformFeedback::KIND_TRIAL_EXIT, PlatformFeedback::KIND_CANCEL_EXIT])
                ->orWhere(fn ($q) => $q->where('kind', PlatformFeedback::KIND_NPS)->where('score', '<=', 6))
                ->orWhere(fn ($q) => $q->where('kind', PlatformFeedback::KIND_SUPPORT_CSAT)->where('score', '<=', 2))
                ->orWhere(fn ($q) => $q->where('kind', PlatformFeedback::KIND_CES)->where('score', 1)));
    }

    /** Roda em segundo plano: e-mail (PLATFORM_ADMIN_EMAILS) + WhatsApp (PLATFORM_SUPPORT_WHATSAPP). */
    public function sendAlert(int $feedbackId): void
    {
        $feedback = PlatformFeedback::with(['company:id,name,plan', 'user:id,name,email', 'supportTicket:id,subject'])->find($feedbackId);
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

    /** Título do aviso (WhatsApp e assunto do e-mail). */
    public static function alertTitle(PlatformFeedback $feedback): string
    {
        $shop = $feedback->company?->name ?? 'loja';

        return match ($feedback->kind) {
            PlatformFeedback::KIND_TRIAL_EXIT => "🧪 Teste acabou sem assinar — {$shop}",
            PlatformFeedback::KIND_CANCEL_EXIT => "💔 Cancelou a assinatura — {$shop}",
            PlatformFeedback::KIND_SUPPORT_CSAT => ($feedback->score <= 2 ? '🔴' : ($feedback->score === 3 ? '🟡' : '🟢'))." Atendimento {$feedback->score}/5 — {$shop}",
            PlatformFeedback::KIND_CES => ($feedback->score === 1 ? '😣 Achou difícil' : ($feedback->score === 2 ? '😐 Mais ou menos' : '😄 Achou fácil')).": {$feedback->reasonLabel()} — {$shop}",
            default => ($feedback->score <= 6 ? '🔴' : ($feedback->score <= 8 ? '🟡' : '🟢'))." NPS {$feedback->score}/10 — {$shop}",
        };
    }

    public function alertText(PlatformFeedback $feedback): string
    {
        $days = $feedback->context['days_since_signup'] ?? null;
        $ticket = $feedback->supportTicket;

        return implode("\n", array_filter([
            '*'.self::alertTitle($feedback).'*',
            $ticket ? "Chamado #{$ticket->id}: {$ticket->subject}" : null,
            $feedback->reason && $feedback->kind !== PlatformFeedback::KIND_CES ? 'Motivo: '.$feedback->reasonLabel() : null,
            $feedback->comment ? '"'.Str::limit($feedback->comment, 600).'"' : null,
            ($feedback->user?->name ?? 'Dono').($days !== null ? " · {$days} dias de conta" : '').($feedback->channel === 'email' ? ' · pelo e-mail' : ''),
            '',
            rtrim((string) config('services.frontend_url'), '/').($ticket ? "/admin/suporte/{$ticket->id}" : '/admin/opinioes'),
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
    private function context(Company $company, ?User $user, array $extra = []): array
    {
        return array_filter([
            'plan' => $company->plan,
            'subscribed' => $company->subscribed('default'),
            'days_since_signup' => (int) $company->created_at->diffInDays(now()),
            'trial_source' => $company->pro_trial_source,
            'segment' => $company->segment,
            'role' => $user?->role,
            ...$extra,
        ], fn ($v) => $v !== null);
    }
}
