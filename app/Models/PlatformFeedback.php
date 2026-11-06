<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opinião do lojista sobre o Meu Custo Total (ver App\Services\PlatformFeedbackService):
 *  - nps: "de 0 a 10, quanto indicaria" + comentário opcional;
 *  - trial_exit: por que não assinou quando o teste grátis do Pro acabou (uma por loja; pelo app ou pelo e-mail);
 *  - support_csat: nota de 1 a 5 do atendimento num chamado resolvido (uma por chamado);
 *  - cancel_exit: por que cancelou a assinatura (uma por assinatura; context.subscription_id).
 */
class PlatformFeedback extends Model
{
    protected $table = 'platform_feedback';

    public const KIND_NPS = 'nps';

    public const KIND_TRIAL_EXIT = 'trial_exit';

    public const KIND_SUPPORT_CSAT = 'support_csat';

    public const KIND_CANCEL_EXIT = 'cancel_exit';

    public const KINDS = [self::KIND_NPS, self::KIND_TRIAL_EXIT, self::KIND_SUPPORT_CSAT, self::KIND_CANCEL_EXIT];

    /** Motivos do fim do teste — os mesmos textos estão em src/lib/feedback.ts no frontend. */
    public const TRIAL_EXIT_REASONS = [
        'price' => 'Achei caro',
        'missing_feature' => 'Faltou um recurso que eu preciso',
        'no_time' => 'Não tive tempo de testar',
        'free_enough' => 'O plano Grátis já me atende',
        'hard_to_use' => 'Achei difícil de usar',
        'other' => 'Outro motivo',
    ];

    /** Motivos do cancelamento — os mesmos textos estão em src/lib/feedback.ts no frontend. */
    public const CANCEL_EXIT_REASONS = [
        'price' => 'Ficou caro para mim',
        'not_using' => 'Não estou usando o suficiente',
        'missing_feature' => 'Faltou um recurso que eu preciso',
        'problems' => 'Tive problemas ou erros no sistema',
        'business' => 'Pausei ou fechei o negócio',
        'other_tool' => 'Vou usar outra ferramenta',
        'other' => 'Outro motivo',
    ];

    protected $fillable = [
        'company_id', 'user_id', 'support_ticket_id', 'kind', 'score', 'reason', 'comment', 'channel', 'context', 'followed_up_at', 'follow_up_note',
    ];

    protected $casts = [
        'score' => 'integer',
        'context' => 'array',
        'followed_up_at' => 'datetime',
    ];

    /** Motivos válidos para o tipo (vazio = o tipo não tem motivo). */
    public static function reasonsFor(string $kind): array
    {
        return match ($kind) {
            self::KIND_TRIAL_EXIT => self::TRIAL_EXIT_REASONS,
            self::KIND_CANCEL_EXIT => self::CANCEL_EXIT_REASONS,
            default => [],
        };
    }

    /** Faixa da nota: NPS de 0 a 10; atendimento de 1 a 5; os outros não têm nota. */
    public static function scoreRange(string $kind): ?array
    {
        return match ($kind) {
            self::KIND_NPS => [0, 10],
            self::KIND_SUPPORT_CSAT => [1, 5],
            default => null,
        };
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    /** Pede conversa: fim do teste, cancelamento, NPS 0–6 ou atendimento 1–2. */
    public function isNegative(): bool
    {
        return match ($this->kind) {
            self::KIND_TRIAL_EXIT, self::KIND_CANCEL_EXIT => true,
            self::KIND_NPS => $this->score !== null && $this->score <= 6,
            self::KIND_SUPPORT_CSAT => $this->score !== null && $this->score <= 2,
            default => false,
        };
    }

    /** promoter (9–10) · passive (7–8) · detractor (0–6) — só NPS. */
    public function npsGroup(): ?string
    {
        if ($this->kind !== self::KIND_NPS || $this->score === null) {
            return null;
        }

        return $this->score >= 9 ? 'promoter' : ($this->score >= 7 ? 'passive' : 'detractor');
    }

    public function reasonLabel(): ?string
    {
        return $this->reason ? (self::reasonsFor($this->kind)[$this->reason] ?? $this->reason) : null;
    }
}
