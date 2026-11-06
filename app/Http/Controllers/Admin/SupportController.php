<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformTestimonial;
use App\Models\SupportTicket;
use App\Services\AdminAudit;
use App\Services\PlatformFeedbackService;
use App\Services\SupportNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Área admin → Suporte: chamados das lojas, responder e mudar a situação. */
class SupportController extends Controller
{
    public function __construct(private SupportNotifier $notifier) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...SupportTicket::STATUSES, 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
            'company_id' => ['nullable', 'integer'],
        ]);
        $status = $data['status'] ?? SupportTicket::STATUS_OPEN;
        $search = trim($data['search'] ?? '');

        $tickets = SupportTicket::query()
            ->with(['company:id,name,plan', 'user:id,name,email'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('subject', 'like', "%{$search}%")
                ->orWhere('id', ltrim($search, '#'))
                ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%"))))
            // Na fila do suporte, o que está esperando há mais tempo vem primeiro
            ->when($status === SupportTicket::STATUS_OPEN, fn ($q) => $q->orderBy('last_message_at'), fn ($q) => $q->orderByDesc('last_message_at'))
            ->paginate(30)
            ->through(fn (SupportTicket $t) => [
                'id' => $t->id,
                'category' => $t->category,
                'subject' => $t->subject,
                'status' => $t->status,
                'unread' => $t->unread_by_admin,
                'company' => $t->company ? ['id' => $t->company->id, 'name' => $t->company->name, 'plan' => $t->company->plan] : null,
                'user' => $t->user ? ['name' => $t->user->name, 'email' => $t->user->email] : null,
                'last_message_at' => $t->last_message_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return [
            ...$tickets->toArray(),
            'counts' => SupportTicket::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ];
    }

    /** Contador do menu admin: chamados com mensagem da loja ainda não lida. */
    public function summary()
    {
        return [
            'unread' => SupportTicket::where('unread_by_admin', true)->count(),
            // Opiniões que pedem conversa (fim do teste ou NPS 0–6 sem retorno): contador de Admin → Opiniões
            'feedback_pending' => PlatformFeedbackService::pendingQuery()->count()
                + PlatformTestimonial::where('status', PlatformTestimonial::STATUS_PENDING)->count(), // depoimento esperando aprovação
        ];
    }

    public function show(SupportTicket $ticket)
    {
        if ($ticket->unread_by_admin) {
            $ticket->forceFill(['unread_by_admin' => false])->saveQuietly();
        }

        return $this->detail($ticket);
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            // Responder e já encerrar ("resolvido"), ou deixar aguardando a loja
            'resolve' => ['sometimes', 'boolean'],
        ], ['body.required' => 'Escreva a resposta.', 'body.max' => 'A resposta pode ter até 5000 letras.']);
        $resolve = (bool) ($data['resolve'] ?? false);

        $message = DB::transaction(function () use ($ticket, $data, $request, $resolve) {
            $message = $ticket->messages()->create(['user_id' => $request->user()->id, 'from_admin' => true, 'body' => trim($data['body'])]);
            $ticket->update([
                'status' => $resolve ? SupportTicket::STATUS_RESOLVED : SupportTicket::STATUS_ANSWERED,
                'resolved_at' => $resolve ? now() : null,
                'unread_by_user' => true,
                'unread_by_admin' => false,
                'last_message_at' => now(),
            ]);

            return $message;
        });

        AdminAudit::log($request, 'support.reply', $ticket->company_id, $ticket->user_id, ['ticket' => $ticket->id, 'resolve' => $resolve]);
        $this->notifier->toUser($ticket, $message);

        return $this->detail($ticket->fresh());
    }

    /** Muda só a situação (sem mensagem) — ex: reabrir, ou encerrar um chamado que se resolveu sozinho. */
    public function status(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['status' => ['required', Rule::in(SupportTicket::STATUSES)]]);

        $ticket->update([
            'status' => $data['status'],
            'resolved_at' => $data['status'] === SupportTicket::STATUS_RESOLVED ? ($ticket->resolved_at ?? now()) : null,
            'unread_by_admin' => false,
        ]);
        AdminAudit::log($request, 'support.status', $ticket->company_id, $ticket->user_id, ['ticket' => $ticket->id, 'status' => $data['status']]);

        return $this->detail($ticket->fresh());
    }

    private function detail(SupportTicket $t): array
    {
        $t->loadMissing(['company:id,name,plan,slug,blocked_at', 'user:id,name,email,role,phone', 'messages.user:id,name', 'rating']);

        return [
            'id' => $t->id,
            'category' => $t->category,
            'subject' => $t->subject,
            'status' => $t->status,
            'page_url' => $t->page_url,
            'context' => $t->context,
            'created_at' => $t->created_at?->toIso8601String(),
            'resolved_at' => $t->resolved_at?->toIso8601String(),
            // Avaliação do atendimento dada pela loja (1–5)
            'rating' => $t->rating ? ['score' => $t->rating->score, 'comment' => $t->rating->comment, 'at' => $t->rating->updated_at?->toIso8601String()] : null,
            'company' => $t->company ? [
                'id' => $t->company->id,
                'name' => $t->company->name,
                'plan' => $t->company->plan,
                'blocked' => $t->company->blocked_at !== null,
            ] : null,
            'user' => $t->user ? [
                'id' => $t->user->id,
                'name' => $t->user->name,
                'email' => $t->user->email,
                'role' => $t->user->role,
                'phone' => $t->user->phone,
            ] : null,
            'messages' => $t->messages->map(fn ($m) => [
                'id' => $m->id,
                'from_admin' => $m->from_admin,
                'author' => $m->user?->name ?? ($m->from_admin ? 'Suporte' : 'Equipe da loja'),
                'body' => $m->body,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
