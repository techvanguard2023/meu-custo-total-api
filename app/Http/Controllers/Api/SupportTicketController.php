<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\AdminAudit;
use App\Services\SupportNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "Ajuda e suporte": qualquer pessoa da equipe abre e acompanha chamados com a plataforma.
 * O dono vê todos os chamados da loja; vendas e produção, só os que abriram.
 * Fica fora do bloqueio da conta (EnsureAccountActive): loja suspensa precisa conseguir pedir ajuda.
 */
class SupportTicketController extends Controller
{
    public function __construct(private SupportNotifier $notifier) {}

    public function index(Request $request)
    {
        return $this->visible($request)
            ->with('user:id,name')
            ->orderByRaw("status = 'resolved'")
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get()
            ->map(fn (SupportTicket $t) => $this->row($t));
    }

    /** Contador do menu: chamados com resposta do suporte que a pessoa ainda não viu. */
    public function unread(Request $request)
    {
        return ['count' => $this->visible($request)->where('unread_by_user', true)->count()];
    }

    public function store(Request $request)
    {
        $this->refuseImpersonation($request);

        $data = $request->validate([
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'subject' => ['required', 'string', 'min:3', 'max:120'],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
            'page_url' => ['nullable', 'string', 'max:500'],
            'context' => ['nullable', 'array'],
            'context.user_agent' => ['nullable', 'string', 'max:300'],
            'context.screen' => ['nullable', 'string', 'max:30'],
            'context.app_version' => ['nullable', 'string', 'max:40'],
        ], [
            'category.*' => 'Escolha o tipo do chamado.',
            'subject.required' => 'Escreva o assunto.',
            'subject.min' => 'O assunto precisa de pelo menos 3 letras.',
            'subject.max' => 'O assunto pode ter até 120 letras.',
            'body.required' => 'Conte o que aconteceu.',
            'body.min' => 'Conte um pouco mais sobre o que aconteceu.',
            'body.max' => 'A descrição pode ter até 5000 letras.',
        ]);

        $user = $request->user();

        [$ticket, $message] = DB::transaction(function () use ($data, $user) {
            $ticket = SupportTicket::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'category' => $data['category'],
                'subject' => trim($data['subject']),
                'status' => SupportTicket::STATUS_OPEN,
                'page_url' => $data['page_url'] ?? null,
                'context' => array_filter([
                    ...($data['context'] ?? []),
                    'role' => $user->role,
                    'plan' => $user->company?->plan,
                ]) ?: null,
                'unread_by_admin' => true,
                'last_message_at' => now(),
            ]);
            $message = $ticket->messages()->create(['user_id' => $user->id, 'from_admin' => false, 'body' => trim($data['body'])]);

            return [$ticket, $message];
        });

        $this->notifier->toPlatform($ticket, $message, true);

        return response()->json($this->detail($ticket->fresh()), 201);
    }

    public function show(Request $request, SupportTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);

        // Quem está "dentro" da loja pela área admin não marca a resposta como lida pela loja
        if ($ticket->unread_by_user && ! $this->impersonating($request)) {
            $ticket->forceFill(['unread_by_user' => false])->saveQuietly();
        }

        return $this->detail($ticket);
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);
        $this->refuseImpersonation($request);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']], [
            'body.required' => 'Escreva a mensagem.',
            'body.max' => 'A mensagem pode ter até 5000 letras.',
        ]);

        $message = DB::transaction(function () use ($ticket, $data, $request) {
            $message = $ticket->messages()->create(['user_id' => $request->user()->id, 'from_admin' => false, 'body' => trim($data['body'])]);
            // Mensagem da loja devolve o chamado pro suporte — inclusive um já resolvido (reabre)
            $ticket->update([
                'status' => SupportTicket::STATUS_OPEN,
                'unread_by_admin' => true,
                'unread_by_user' => false,
                'last_message_at' => now(),
                'resolved_at' => null,
            ]);

            return $message;
        });

        $this->notifier->toPlatform($ticket, $message, false);

        return $this->detail($ticket->fresh());
    }

    /** A própria loja encerra: "meu problema foi resolvido". */
    public function resolve(Request $request, SupportTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);
        $this->refuseImpersonation($request);

        $ticket->update(['status' => SupportTicket::STATUS_RESOLVED, 'resolved_at' => now(), 'unread_by_user' => false]);

        return $this->detail($ticket->fresh());
    }

    /** Chamados que a pessoa enxerga: dono, todos da loja; equipe, só os próprios. */
    private function visible(Request $request)
    {
        $user = $request->user();

        return SupportTicket::where('company_id', $user->company_id)
            ->when(! $user->isOwner(), fn ($q) => $q->where('user_id', $user->id));
    }

    private function authorizeTicket(Request $request, SupportTicket $ticket): void
    {
        $user = $request->user();
        abort_unless(
            $ticket->company_id === $user->company_id && ($user->isOwner() || $ticket->user_id === $user->id),
            404
        );
    }

    private function impersonating(Request $request): bool
    {
        return AdminAudit::impersonatorId($request->user()->currentAccessToken()) !== null;
    }

    /** No "Entrar como" o admin só lê: chamado aberto ou respondido por ele sairia em nome da loja. */
    private function refuseImpersonation(Request $request): void
    {
        abort_if($this->impersonating($request), 403, 'Você está dentro da loja pela área admin — responda o chamado em Admin → Suporte.');
    }

    private function row(SupportTicket $t): array
    {
        return [
            'id' => $t->id,
            'category' => $t->category,
            'subject' => $t->subject,
            'status' => $t->status,
            'unread' => $t->unread_by_user,
            'opened_by' => $t->user?->name,
            'last_message_at' => $t->last_message_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    private function detail(SupportTicket $t): array
    {
        $t->loadMissing(['user:id,name', 'messages.user:id,name']);

        return [
            ...$this->row($t),
            'resolved_at' => $t->resolved_at?->toIso8601String(),
            'messages' => $t->messages->map(fn ($m) => [
                'id' => $m->id,
                'from_admin' => $m->from_admin,
                // Do lado da loja, o nome de quem atende não aparece: é "Suporte"
                'author' => $m->from_admin ? 'Suporte Meu Custo Total' : ($m->user?->name ?? 'Equipe da loja'),
                'body' => $m->body,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
