<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformFeedback;
use App\Services\AdminAudit;
use App\Services\PlatformFeedbackService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Opinião do lojista sobre a plataforma: o app pergunta qual pesquisa mostrar (NPS ou fim do teste), grava a
 * resposta ou o "agora não". O link do e-mail de fim do teste responde sem login (/opiniao/{token}).
 */
class PlatformFeedbackController extends Controller
{
    public function __construct(private PlatformFeedbackService $feedback) {}

    /** Pesquisa a mostrar agora (ou kind null). No "Entrar como" nunca aparece. */
    public function prompt(Request $request)
    {
        if ($this->impersonating($request)) {
            return ['kind' => null];
        }

        return $this->feedback->prompt($request->user()) ?? ['kind' => null];
    }

    public function store(Request $request)
    {
        abort_if($this->impersonating($request), 403, 'Você está dentro da loja pela área admin — a opinião é só do lojista.');
        $data = self::validated($request);
        $user = $request->user();

        // Só aceita a pesquisa que está valendo pra pessoa (não dá pra mandar NPS a qualquer hora pela API).
        // A avaliação de chamado tem rota própria (POST /support/{ticket}/rating).
        abort_if($data['kind'] === PlatformFeedback::KIND_SUPPORT_CSAT, 422, 'Avalie o atendimento pela tela do chamado.');
        abort_unless($this->feedback->due($user) === $data['kind'], 422, 'Esta pesquisa não está disponível agora.');

        $about = $data['kind'] === PlatformFeedback::KIND_CANCEL_EXIT
            ? ['subscription_id' => $this->feedback->cancelExitSubscription($user->company, now())?->stripe_id]
            : [];
        $feedback = $this->feedback->record($user->company, $user, $data, 'app', $about);

        return response()->json(['id' => $feedback->id], 201);
    }

    public function dismiss(Request $request)
    {
        if ($this->impersonating($request)) {
            return response()->noContent();
        }
        $data = $request->validate([
            'kind' => ['required', Rule::in(PlatformFeedback::KINDS)],
            'ticket_id' => ['nullable', 'integer'],
        ]);
        $this->feedback->dismiss($request->user(), $data['kind'], $data['ticket_id'] ?? null);

        return response()->noContent();
    }

    /** Link do e-mail: o que perguntar e a resposta já dada (se houver). */
    public function publicShow(string $token)
    {
        $link = $this->feedback->parseToken($token);
        abort_unless($link && $link['kind'] === PlatformFeedback::KIND_TRIAL_EXIT, 404, 'Este link venceu ou não é válido.');

        $answer = PlatformFeedback::where('company_id', $link['user']->company_id)->where('kind', $link['kind'])->first(['reason', 'comment']);

        return [
            'kind' => $link['kind'],
            'company_name' => $link['user']->company->name,
            'first_name' => strtok((string) $link['user']->name, ' ') ?: null,
            'answer' => $answer ? ['reason' => $answer->reason, 'comment' => $answer->comment] : null,
        ];
    }

    public function publicStore(Request $request, string $token)
    {
        $link = $this->feedback->parseToken($token);
        abort_unless($link && $link['kind'] === PlatformFeedback::KIND_TRIAL_EXIT, 404, 'Este link venceu ou não é válido.');

        $data = self::validated($request->merge(['kind' => $link['kind']]), false);
        $this->feedback->record($link['user']->company, $link['user'], $data, 'email');

        return response()->json(['ok' => true], 201);
    }

    /** Valida nota (faixa do tipo), motivo (lista do tipo) e comentário. Também usado na avaliação do chamado. */
    public static function validated(Request $request, bool $withKind = true): array
    {
        $kind = (string) $request->input('kind');
        $range = PlatformFeedback::scoreRange($kind);
        $reasons = array_keys(PlatformFeedback::reasonsFor($kind));

        return $request->validate([
            'kind' => [$withKind ? 'required' : 'nullable', Rule::in(PlatformFeedback::KINDS)],
            'score' => [Rule::requiredIf($range !== null), 'nullable', 'integer', 'between:'.($range[0] ?? 0).','.($range[1] ?? 10)],
            'reason' => [Rule::requiredIf($reasons !== []), 'nullable', Rule::in($reasons)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'score.*' => 'Escolha uma nota.',
            'reason.*' => 'Escolha um motivo.',
            'comment.max' => 'O comentário pode ter até 1000 letras.',
        ]);
    }

    private function impersonating(Request $request): bool
    {
        return AdminAudit::impersonatorId($request->user()?->currentAccessToken()) !== null;
    }
}
