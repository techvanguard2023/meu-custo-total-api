<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformFeedback;
use App\Services\AdminAudit;
use App\Services\PlatformFeedbackService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Área admin → Opiniões: respostas do NPS e do fim do teste, com o "conversei com a loja". */
class FeedbackController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(PlatformFeedback::KINDS)],
            'group' => ['nullable', Rule::in(['promoter', 'passive', 'detractor'])],
            'pending' => ['nullable', 'boolean'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $query = ($request->boolean('pending') ? PlatformFeedbackService::pendingQuery() : PlatformFeedback::query())
            ->with(['company:id,name,plan', 'user:id,name,email'])
            ->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($data['group'] ?? null, fn ($q, $group) => $q->where('kind', PlatformFeedback::KIND_NPS)->where(fn ($q) => match ($group) {
                'promoter' => $q->where('score', '>=', 9),
                'passive' => $q->whereBetween('score', [7, 8]),
                default => $q->where('score', '<=', 6),
            }))
            ->latest('id');

        return $query->paginate(30)->through(fn (PlatformFeedback $f) => self::row($f));
    }

    public function update(Request $request, PlatformFeedback $feedback)
    {
        $data = $request->validate([
            'followed_up' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $feedback->update([
            'followed_up_at' => $data['followed_up'] ? ($feedback->followed_up_at ?? now()) : null,
            'follow_up_note' => isset($data['note']) ? (trim($data['note']) ?: null) : $feedback->follow_up_note,
        ]);
        AdminAudit::log($request, 'feedback.follow_up', $feedback->company_id, $feedback->user_id, [
            'feedback_id' => $feedback->id,
            'followed_up' => $data['followed_up'],
        ]);

        return self::row($feedback->load(['company:id,name,plan', 'user:id,name,email']));
    }

    public static function row(PlatformFeedback $f): array
    {
        return [
            'id' => $f->id,
            'kind' => $f->kind,
            'score' => $f->score,
            'group' => $f->npsGroup(),
            'reason' => $f->reason,
            'reason_label' => $f->reasonLabel(),
            'comment' => $f->comment,
            'channel' => $f->channel,
            'context' => $f->context,
            'followed_up_at' => $f->followed_up_at?->toIso8601String(),
            'follow_up_note' => $f->follow_up_note,
            'created_at' => $f->created_at?->toIso8601String(),
            'company' => $f->company ? ['id' => $f->company->id, 'name' => $f->company->name, 'plan' => $f->company->plan] : null,
            'user' => $f->user ? ['name' => $f->user->name, 'email' => $f->user->email] : null,
        ];
    }
}
