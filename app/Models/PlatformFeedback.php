<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opinião do lojista sobre o Meu Custo Total (ver App\Services\PlatformFeedbackPrompt):
 *  - nps: "de 0 a 10, quanto indicaria" + comentário opcional;
 *  - trial_exit: por que não assinou quando o teste grátis do Pro acabou (uma por loja; pelo app ou pelo e-mail).
 */
class PlatformFeedback extends Model
{
    protected $table = 'platform_feedback';

    public const KIND_NPS = 'nps';

    public const KIND_TRIAL_EXIT = 'trial_exit';

    public const KINDS = [self::KIND_NPS, self::KIND_TRIAL_EXIT];

    /** Motivos do fim do teste — os mesmos textos estão em src/lib/feedback.ts no frontend. */
    public const TRIAL_EXIT_REASONS = [
        'price' => 'Achei caro',
        'missing_feature' => 'Faltou um recurso que eu preciso',
        'no_time' => 'Não tive tempo de testar',
        'free_enough' => 'O plano Grátis já me atende',
        'hard_to_use' => 'Achei difícil de usar',
        'other' => 'Outro motivo',
    ];

    protected $fillable = [
        'company_id', 'user_id', 'kind', 'score', 'reason', 'comment', 'channel', 'context', 'followed_up_at', 'follow_up_note',
    ];

    protected $casts = [
        'score' => 'integer',
        'context' => 'array',
        'followed_up_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
        return $this->reason ? (self::TRIAL_EXIT_REASONS[$this->reason] ?? $this->reason) : null;
    }
}
