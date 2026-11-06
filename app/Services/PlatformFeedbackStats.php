<?php

namespace App\Services;

use App\Models\PlatformFeedback;

/**
 * Visão geral → Opinião das lojas: NPS dos últimos 90 dias (e dos 90 anteriores, pra tendência), nota do
 * atendimento, motivos de quem não assinou depois do teste e de quem cancelou, quantas respostas esperam conversa e
 * os últimos comentários.
 */
class PlatformFeedbackStats
{
    public const WINDOW_DAYS = 90;

    public function summary(): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $before = now()->subDays(self::WINDOW_DAYS * 2);

        $csat = PlatformFeedback::where('kind', PlatformFeedback::KIND_SUPPORT_CSAT)->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) AS total, SUM(score >= 4) AS satisfied, AVG(score) AS average')->first();
        $csatTotal = (int) $csat->total;

        return [
            'window_days' => self::WINDOW_DAYS,
            'nps' => $this->nps($since, now()),
            'nps_previous' => $this->nps($before, $since)['score'],
            'trial_exit' => $this->reasons(PlatformFeedback::KIND_TRIAL_EXIT, $since),
            'cancel_exit' => $this->reasons(PlatformFeedback::KIND_CANCEL_EXIT, $since),
            // Atendimento: % de notas 4–5 (CSAT) e a média, nos chamados avaliados
            'support_csat' => [
                'total' => $csatTotal,
                'satisfied' => (int) $csat->satisfied,
                'rate' => $csatTotal ? (int) round((int) $csat->satisfied / $csatTotal * 100) : null,
                'average' => $csatTotal ? round((float) $csat->average, 1) : null,
            ],
            'pending' => PlatformFeedbackService::pendingQuery()->count(),
            'recent_comments' => PlatformFeedback::with('company:id,name')->whereNotNull('comment')->latest('id')->limit(5)->get()
                ->map(fn (PlatformFeedback $f) => [
                    'id' => $f->id,
                    'company_id' => $f->company_id,
                    'company_name' => $f->company?->name,
                    'kind' => $f->kind,
                    'score' => $f->score,
                    'reason_label' => $f->reasonLabel(),
                    'comment' => $f->comment,
                    'created_at' => $f->created_at?->toIso8601String(),
                ]),
        ];
    }

    /** Motivos de um tipo (fim do teste, cancelamento) no período, do mais citado ao menos. */
    private function reasons(string $kind, $since): array
    {
        $rows = PlatformFeedback::where('kind', $kind)->where('created_at', '>=', $since)
            ->selectRaw('reason, COUNT(*) AS total')->groupBy('reason')->orderByDesc('total')->pluck('total', 'reason');
        $labels = PlatformFeedback::reasonsFor($kind);

        return [
            'total' => (int) $rows->sum(),
            'reasons' => $rows->map(fn ($total, $reason) => ['reason' => $reason, 'label' => $labels[$reason] ?? $reason, 'total' => (int) $total])->values(),
        ];
    }

    /** NPS = % promotores (9–10) − % detratores (0–6). Null sem respostas. */
    private function nps($from, $to): array
    {
        $row = PlatformFeedback::where('kind', PlatformFeedback::KIND_NPS)->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('COUNT(*) AS total, SUM(score >= 9) AS promoters, SUM(score BETWEEN 7 AND 8) AS passives, SUM(score <= 6) AS detractors')
            ->first();
        $total = (int) $row->total;

        return [
            'total' => $total,
            'promoters' => (int) $row->promoters,
            'passives' => (int) $row->passives,
            'detractors' => (int) $row->detractors,
            'score' => $total ? (int) round(((int) $row->promoters - (int) $row->detractors) / $total * 100) : null,
        ];
    }
}
