<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArtBrief;
use App\Models\ArtBriefFile;
use App\Services\ArtBriefNotifier;
use App\Services\ArtBriefs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Página pública do briefing da arte (sem login): o cliente responde as perguntas da loja, anexa referências e envia.
 * Os anexos sobem um a um enquanto ele preenche; o envio final confere as obrigatórias e trava o briefing.
 */
class PublicArtBriefController extends Controller
{
    public const MAX_FILE_KB = 10240;

    public function show(string $token): JsonResponse
    {
        $brief = $this->find($token);
        if ($brief->viewed_at === null) {
            $brief->forceFill(['viewed_at' => now()])->saveQuietly();
        }
        $company = $brief->company;
        $first = explode(' ', trim((string) $brief->quote->customer?->name))[0];

        return response()->json([
            'status' => $brief->status,
            'can_answer' => $brief->isOpen(),
            'store' => ['name' => $company->name, 'logo_url' => $company->logo_url, 'accent_color' => $company->catalog_accent_color],
            'order_name' => trim(explode('|__JSON__|', (string) $brief->quote->name)[0]) ?: null,
            'customer_first_name' => $first !== '' ? $first : null,
            'questions' => $brief->questions,
            'answers' => $brief->answers ?? (object) [],
            'files' => $brief->files->map(fn (ArtBriefFile $f) => $this->file($f))->values(),
            'max_files' => ArtBriefs::MAX_FILES,
        ]);
    }

    /** Um anexo de referência (imagem, PDF ou modelo 3D), ligado à pergunta de arquivos. */
    public function upload(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'question_key' => ['required', 'string', 'max:40'],
            'file' => ['required', 'file', 'max:'.self::MAX_FILE_KB, ArtProofController::allowedFile()],
        ], ['file.max' => 'Cada arquivo pode ter no máximo 10 MB.', 'file.required' => 'Escolha um arquivo.']);

        $file = DB::transaction(function () use ($request, $token, $data) {
            $brief = $this->openLocked($token);
            abort_unless(collect($brief->questions)->contains(fn ($q) => $q['key'] === $data['question_key'] && $q['type'] === 'files'), 422, 'Pergunta inválida.');
            abort_if($brief->files()->count() >= ArtBriefs::MAX_FILES, 422, 'No máximo '.ArtBriefs::MAX_FILES.' arquivos.');

            $upload = $request->file('file');
            $extension = strtolower($upload->getClientOriginalExtension() ?: $upload->guessExtension() ?: 'bin');

            return $brief->files()->create([
                'question_key' => $data['question_key'],
                'path' => $upload->storeAs("art-briefs/{$brief->company_id}", Str::random(40).'.'.$extension, 'public'),
                'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                'mime' => $upload->getClientMimeType(),
                'size' => $upload->getSize(),
            ]);
        });

        return response()->json($this->file($file), 201);
    }

    public function removeFile(string $token, int $file): JsonResponse
    {
        DB::transaction(function () use ($token, $file) {
            $brief = $this->openLocked($token);
            $row = $brief->files()->whereKey($file)->firstOrFail();
            \Illuminate\Support\Facades\Storage::disk('public')->delete($row->path);
            $row->delete();
        });

        return response()->json(null, 204);
    }

    /** Envio final: confere as respostas (obrigatórias) e trava o briefing. */
    public function submit(Request $request, string $token, ArtBriefNotifier $notifier): JsonResponse
    {
        $data = $request->validate(['answers' => ['present', 'array']]);

        $brief = DB::transaction(function () use ($token, $data) {
            $brief = $this->openLocked($token);
            $brief->forceFill([
                'answers' => ArtBriefs::cleanAnswers($brief, $data['answers']),
                'status' => ArtBrief::SUBMITTED,
                'submitted_at' => now(),
                'seen_at' => null,
            ])->save();

            return $brief;
        });

        $notifier->notifyStore($brief);

        return $this->show($token);
    }

    private function find(string $token): ArtBrief
    {
        return ArtBrief::with(['files', 'company', 'quote.customer'])->where('token', $token)->firstOrFail();
    }

    private function openLocked(string $token): ArtBrief
    {
        $brief = ArtBrief::where('token', $token)->lockForUpdate()->firstOrFail();
        abort_unless($brief->isOpen(), 409, 'Este briefing já foi enviado. Se quiser mudar alguma coisa, fale com a loja.');

        return $brief;
    }

    private function file(ArtBriefFile $f): array
    {
        return ['id' => $f->id, 'question_key' => $f->question_key, 'name' => $f->original_name, 'url' => $f->url, 'size' => $f->size, 'is_image' => $f->is_image];
    }
}
