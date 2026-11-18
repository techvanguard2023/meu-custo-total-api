<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\ArtProof;
use App\Models\ArtProofFile;
use App\Models\Quote;
use App\Services\ArtProofNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Aprovação de arte (lado da loja): manda a arte ou o modelo pro cliente conferir e aprovar antes de produzir.
 * A resposta do cliente é registrada na página pública (PublicArtProofController).
 */
class ArtProofController extends Controller
{
    use EnforcesPlanLimits;

    public const MAX_FILES = 8;

    public const MAX_FILE_KB = 10240;

    /** Provas de um pedido, da mais nova pra mais antiga, com o termo que será usado no próximo envio. */
    public function index(Request $request, Quote $quote): JsonResponse
    {
        $this->requireEssential($request, 'Aprovação de arte');
        $this->authorizeQuote($request, $quote);

        return response()->json([
            'proofs' => $quote->artProofs()->with(['files', 'company'])->get()->map(fn (ArtProof $p) => $this->row($p)),
            'terms' => $request->user()->company->art_approval_terms ?: ArtProof::DEFAULT_TERMS,
        ]);
    }

    /** Nova versão da arte. As anteriores que ainda esperavam resposta passam a "substituída". */
    public function store(Request $request, Quote $quote, ArtProofNotifier $notifier): JsonResponse
    {
        $this->requireEssential($request, 'Aprovação de arte');
        $this->authorizeQuote($request, $quote);
        abort_if(in_array($quote->status, [Quote::STATUS_REJECTED, Quote::STATUS_CANCELLED], true) || $quote->cancelled_at !== null, 422, 'Este pedido foi cancelado ou recusado.');

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.self::MAX_FILE_KB, $this->allowedFile()],
            'message' => ['nullable', 'string', 'max:1000'],
            'model_url' => ['nullable', 'url', 'max:500'],
            'terms' => ['nullable', 'string', 'max:4000'],
            'send' => ['sometimes', 'boolean'],
            // Várias artes para o cliente escolher uma (sem isso, os arquivos são partes da mesma arte)
            'choose_one' => ['sometimes', 'boolean'],
        ], [
            'files.required' => 'Anexe pelo menos um arquivo da arte.',
            'files.max' => 'No máximo '.self::MAX_FILES.' arquivos por envio.',
            'files.*.mimes' => 'Formato não aceito. Use imagem (JPG, PNG, WebP, GIF), PDF ou modelo 3D (STL, 3MF, OBJ).',
            'files.*.max' => 'Cada arquivo pode ter no máximo 10 MB.',
        ]);

        $company = $request->user()->company;
        $terms = trim((string) ($data['terms'] ?? '')) ?: ($company->art_approval_terms ?: ArtProof::DEFAULT_TERMS);
        $chooseOne = $request->boolean('choose_one');
        abort_if($chooseOne && count($data['files']) < 2, 422, 'Para o cliente escolher, anexe pelo menos duas opções de arte.');

        $proof = DB::transaction(function () use ($request, $quote, $company, $data, $terms, $chooseOne) {
            $quote->artProofs()->whereIn('status', [ArtProof::PENDING, ArtProof::CHANGES_REQUESTED])->update(['status' => ArtProof::SUPERSEDED]);

            $proof = ArtProof::create([
                'company_id' => $company->id,
                'quote_id' => $quote->id,
                'token' => Str::random(40),
                'version' => ((int) $quote->artProofs()->max('version')) + 1,
                'status' => ArtProof::PENDING,
                'message' => trim((string) ($data['message'] ?? '')) ?: null,
                'model_url' => $data['model_url'] ?? null,
                'choose_one' => $chooseOne,
                'terms' => $terms,
            ]);

            foreach (array_values($request->file('files')) as $position => $file) {
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
                $path = $file->storeAs("art-proofs/{$company->id}", Str::random(40).'.'.$extension, 'public');
                $proof->files()->create([
                    'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'position' => $position,
                ]);
            }

            return $proof;
        });

        $delivery = $request->boolean('send') ? $notifier->sendToCustomer($proof) : ['sent' => false, 'reason' => null];

        return response()->json($this->row($proof->load(['files', 'company'])) + ['sent' => $delivery['sent'], 'reason' => $delivery['reason']], 201);
    }

    /** Manda (ou manda de novo) o link pro WhatsApp do cliente. */
    public function send(Request $request, ArtProof $proof, ArtProofNotifier $notifier): JsonResponse
    {
        $this->requireEssential($request, 'Aprovação de arte');
        abort_unless($proof->company_id === $request->user()->company_id, 403);
        abort_unless($proof->isOpen(), 422, 'Esta versão já foi respondida ou substituída.');

        $delivery = $notifier->sendToCustomer($proof);

        return response()->json(['url' => $proof->publicUrl(), 'sent' => $delivery['sent'], 'reason' => $delivery['reason']]);
    }

    /** Apaga uma prova que ainda não foi aprovada (a aprovada é o comprovante: fica). */
    public function destroy(Request $request, ArtProof $proof): JsonResponse
    {
        $this->requireEssential($request, 'Aprovação de arte');
        abort_unless($proof->company_id === $request->user()->company_id, 403);
        abort_if($proof->status === ArtProof::APPROVED, 422, 'Uma arte aprovada fica guardada como comprovante e não pode ser apagada.');

        $proof->delete();

        return response()->json(null, 204);
    }

    /** Termo de aprovação da loja (Configurações). Em branco volta pro texto padrão. */
    public function terms(Request $request): JsonResponse
    {
        return response()->json(['terms' => $request->user()->company->art_approval_terms, 'default' => ArtProof::DEFAULT_TERMS]);
    }

    public function updateTerms(Request $request): JsonResponse
    {
        $this->requireEssential($request, 'Aprovação de arte');
        $data = $request->validate(['terms' => ['nullable', 'string', 'max:4000']]);
        $text = trim((string) ($data['terms'] ?? ''));

        $request->user()->company->update(['art_approval_terms' => $text === '' || $text === ArtProof::DEFAULT_TERMS ? null : $text]);

        return $this->terms($request);
    }

    /**
     * Imagem e PDF: pela extensão E pelo conteúdo (um .exe renomeado pra .png não passa). Modelo 3D (STL, 3MF, OBJ):
     * só pela extensão — não têm um tipo de conteúdo confiável pra conferir.
     */
    public static function allowedFile(): \Closure
    {
        $byContent = ['jpg' => ['jpg', 'jpeg'], 'jpeg' => ['jpg', 'jpeg'], 'png' => ['png'], 'webp' => ['webp'], 'gif' => ['gif'], 'pdf' => ['pdf']];

        return function (string $attribute, mixed $file, \Closure $fail) use ($byContent) {
            $extension = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($extension, ArtProofFile::EXTENSIONS, true)) {
                $fail('Formato não aceito. Use imagem (JPG, PNG, WebP, GIF), PDF ou modelo 3D (STL, 3MF, OBJ).');

                return;
            }
            if (isset($byContent[$extension]) && ! in_array(strtolower((string) $file->guessExtension()), $byContent[$extension], true)) {
                $fail("O arquivo \"{$file->getClientOriginalName()}\" não é um .{$extension} válido.");
            }
        };
    }

    private function authorizeQuote(Request $request, Quote $quote): void
    {
        abort_unless($quote->company_id === $request->user()->company_id, 403);
    }

    private function row(ArtProof $proof): array
    {
        return [
            'id' => $proof->id,
            'version' => $proof->version,
            'status' => $proof->status,
            'message' => $proof->message,
            'model_url' => $proof->model_url,
            'choose_one' => $proof->choose_one,
            'chosen_file_id' => $proof->chosen_file_id,
            'terms' => $proof->terms,
            'url' => $proof->publicUrl(),
            'viewed_at' => $proof->viewed_at?->toIso8601String(),
            'responded_at' => $proof->responded_at?->toIso8601String(),
            'approver_name' => $proof->approver_name,
            'approver_ip' => $proof->approver_ip,
            'customer_comment' => $proof->customer_comment,
            'created_at' => $proof->created_at?->toIso8601String(),
            'files' => $proof->files->map(fn (ArtProofFile $f) => [
                'id' => $f->id,
                'name' => $f->original_name,
                'url' => $f->url,
                'mime' => $f->mime,
                'size' => $f->size,
                'is_image' => $f->is_image,
            ])->values(),
        ];
    }
}
