<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\ArtBrief;
use App\Models\ArtBriefTemplate;
use App\Models\Quote;
use App\Services\AdminAudit;
use App\Services\ArtBriefNotifier;
use App\Services\ArtBriefs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Briefing da arte (lado da loja): as perguntas da loja (Configurações) e o link de cada pedido. O cliente responde
 * na página pública (PublicArtBriefController); as respostas aparecem no pedido.
 */
class ArtBriefController extends Controller
{
    use EnforcesPlanLimits;

    /** Perguntas da loja, as sugeridas do nicho (pra restaurar) e se a loja já mexeu nelas. */
    public function questions(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        return response()->json([
            'questions' => ArtBriefs::questions($company),
            'defaults' => ArtBriefs::defaults($company->segment),
            'customized' => ! empty($company->art_brief_questions),
            'types' => ArtBriefs::TYPES,
            'templates' => ArtBriefs::templates($company),
        ]);
    }

    /** Novo modelo de briefing (perguntas com nome, pra ligar aos produtos). Começa com as perguntas padrão da loja. */
    public function storeTemplate(Request $request): JsonResponse
    {
        $this->requireEssential($request, 'Briefing da arte');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'questions' => ['sometimes', 'nullable', 'array', 'max:'.ArtBriefs::MAX_QUESTIONS],
        ], ['name.required' => 'Dê um nome ao modelo (ex.: Topo de bolo).']);
        $company = $request->user()->company;
        abort_if(ArtBriefTemplate::where('company_id', $company->id)->count() >= 30, 422, 'No máximo 30 modelos de briefing.');

        ArtBriefTemplate::create([
            'company_id' => $company->id,
            'name' => trim($data['name']),
            'questions' => ArtBriefs::normalizeQuestions($data['questions'] ?? ArtBriefs::questions($company)),
        ]);

        return $this->questions($request);
    }

    public function updateTemplate(Request $request, ArtBriefTemplate $template): JsonResponse
    {
        $this->requireEssential($request, 'Briefing da arte');
        abort_unless($template->company_id === $request->user()->company_id, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'questions' => ['required', 'array', 'max:'.ArtBriefs::MAX_QUESTIONS],
        ]);
        $template->update(['name' => trim($data['name']), 'questions' => ArtBriefs::normalizeQuestions($data['questions'])]);

        return $this->questions($request);
    }

    /** Apaga o modelo; os produtos ligados a ele passam a usar as perguntas padrão da loja. */
    public function destroyTemplate(Request $request, ArtBriefTemplate $template): JsonResponse
    {
        $this->requireEssential($request, 'Briefing da arte');
        abort_unless($template->company_id === $request->user()->company_id, 403);
        $template->delete();

        return $this->questions($request);
    }

    /** Salva as perguntas. `questions: null` volta para as do nicho. */
    public function updateQuestions(Request $request): JsonResponse
    {
        $this->requireEssential($request, 'Briefing da arte');
        $data = $request->validate(['questions' => ['present', 'nullable', 'array', 'max:'.ArtBriefs::MAX_QUESTIONS]]);

        $request->user()->company->update([
            'art_brief_questions' => $data['questions'] === null ? null : ArtBriefs::normalizeQuestions($data['questions']),
        ]);

        return $this->questions($request);
    }

    /** O briefing mais recente do pedido. Abrir com respostas novas tira o aviso do menu. */
    public function show(Request $request, Quote $quote): JsonResponse
    {
        $this->requireEssential($request, 'Briefing da arte');
        abort_unless($quote->company_id === $request->user()->company_id, 403);

        $brief = $quote->artBriefs()->with(['files', 'company'])->first();
        if ($brief && $brief->status === ArtBrief::SUBMITTED && ! $brief->seen_at
            && AdminAudit::impersonatorId($request->user()->currentAccessToken()) === null) {
            $brief->forceFill(['seen_at' => now()])->saveQuietly();
        }

        return response()->json(['brief' => $brief ? $this->row($brief) : null]);
    }

    /**
     * Gera o link (ou devolve o que ainda espera resposta) e, com `send`, manda pro WhatsApp do cliente. Com
     * `renew`, troca um briefing já respondido por um novo (as respostas antigas saem).
     */
    public function store(Request $request, Quote $quote, ArtBriefNotifier $notifier): JsonResponse
    {
        $this->requireEssential($request, 'Briefing da arte');
        abort_unless($quote->company_id === $request->user()->company_id, 403);
        abort_if(in_array($quote->status, [Quote::STATUS_REJECTED, Quote::STATUS_CANCELLED], true) || $quote->cancelled_at !== null, 422, 'Este pedido foi cancelado ou recusado.');
        $request->validate(['send' => ['sometimes', 'boolean'], 'renew' => ['sometimes', 'boolean']]);

        $brief = $quote->artBriefs()->first();
        if ($brief && $brief->status === ArtBrief::SUBMITTED) {
            abort_unless($request->boolean('renew'), 422, 'O cliente já respondeu este briefing. Para pedir de novo, gere um novo link.');
            $brief->delete();
            $brief = null;
        }
        // Perguntas dos produtos do pedido (modelo de cada um, ou o padrão da loja); link aberto acompanha as atuais
        $brief = ArtBriefs::openFor($quote);

        $delivery = $request->boolean('send') ? $notifier->sendToCustomer($brief) : ['sent' => false, 'reason' => null];

        return response()->json(['brief' => $this->row($brief->load(['files', 'company']))] + $delivery, 201);
    }

    public function destroy(Request $request, ArtBrief $brief): JsonResponse
    {
        $this->requireEssential($request, 'Briefing da arte');
        abort_unless($brief->company_id === $request->user()->company_id, 403);
        $brief->delete();

        return response()->json(null, 204);
    }

    private function row(ArtBrief $brief): array
    {
        return [
            'id' => $brief->id,
            'status' => $brief->status,
            'url' => $brief->publicUrl(),
            'questions' => $brief->questions,
            'answers' => $brief->answers ?? (object) [],
            'viewed_at' => $brief->viewed_at?->toIso8601String(),
            'submitted_at' => $brief->submitted_at?->toIso8601String(),
            'seen_at' => $brief->seen_at?->toIso8601String(),
            'created_at' => $brief->created_at?->toIso8601String(),
            'files' => $brief->files->map(fn ($f) => ['id' => $f->id, 'question_key' => $f->question_key, 'name' => $f->original_name, 'url' => $f->url, 'size' => $f->size, 'is_image' => $f->is_image])->values(),
        ];
    }
}
