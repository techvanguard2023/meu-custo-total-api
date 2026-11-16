<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArtProof;
use App\Models\ArtProofFile;
use App\Services\ArtProofNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Página pública de aprovação de arte (sem login): o cliente confere os arquivos, lê o termo e aprova ou pede ajuste.
 * A aprovação guarda o nome digitado, a data, o IP e o texto do termo (ArtProof) como comprovante.
 */
class PublicArtProofController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $proof = $this->find($token);

        // Primeira abertura: a loja passa a ver "visualizada" no pedido
        if ($proof->viewed_at === null) {
            $proof->forceFill(['viewed_at' => now()])->saveQuietly();
        }

        $company = $proof->company;
        $first = explode(' ', trim((string) $proof->quote->customer?->name))[0];

        return response()->json([
            'status' => $proof->status,
            'version' => $proof->version,
            'can_respond' => $proof->isOpen(),
            'store' => ['name' => $company->name, 'logo_url' => $company->logo_url, 'accent_color' => $company->catalog_accent_color],
            'order_name' => trim(explode('|__JSON__|', (string) $proof->quote->name)[0]) ?: null,
            'customer_first_name' => $first !== '' ? $first : null,
            'message' => $proof->message,
            'model_url' => $proof->model_url,
            'choose_one' => $proof->choose_one,
            'chosen_file_id' => $proof->chosen_file_id,
            'terms' => $proof->terms,
            'files' => $proof->files->map(fn (ArtProofFile $f) => [
                'id' => $f->id,
                'name' => $f->original_name,
                'url' => $f->url,
                'size' => $f->size,
                'is_image' => $f->is_image,
            ])->values(),
            'approved' => $proof->status === ArtProof::APPROVED
                ? ['name' => $proof->approver_name, 'at' => $proof->responded_at?->toIso8601String()]
                : null,
            'comment' => $proof->status === ArtProof::CHANGES_REQUESTED ? $proof->customer_comment : null,
        ]);
    }

    public function approve(Request $request, string $token, ArtProofNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'accept_terms' => ['accepted'],
            'chosen_file_id' => ['nullable', 'integer'],
        ], [
            'name.required' => 'Digite o seu nome para aprovar.',
            'name.min' => 'Digite o seu nome completo para aprovar.',
            'accept_terms.accepted' => 'Para aprovar, marque que leu e concorda com o termo.',
        ]);

        $proof = $this->respond($token, function (ArtProof $p) use ($data, $request) {
            // Várias opções: o cliente aprova a que escolheu (tem de ser uma desta versão)
            $chosen = null;
            if ($p->choose_one) {
                $chosen = $p->files()->whereKey($data['chosen_file_id'] ?? 0)->value('id');
                abort_unless($chosen, 422, 'Escolha qual das opções de arte você aprova.');
            }

            return $p->forceFill([
                'chosen_file_id' => $chosen,
                'status' => ArtProof::APPROVED,
                'approver_name' => trim($data['name']),
                'approver_ip' => $request->ip(),
                'approver_user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
                'customer_comment' => null,
            ]);
        });

        $notifier->notifyStore($proof);

        return $this->show($token);
    }

    public function requestChanges(Request $request, string $token, ArtProofNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'comment' => ['required', 'string', 'min:3', 'max:1500'],
            'name' => ['nullable', 'string', 'max:120'],
        ], ['comment.required' => 'Conte o que precisa mudar.', 'comment.min' => 'Conte o que precisa mudar.']);

        $proof = $this->respond($token, fn (ArtProof $p) => $p->forceFill([
            'status' => ArtProof::CHANGES_REQUESTED,
            'approver_name' => trim((string) ($data['name'] ?? '')) ?: null,
            'approver_ip' => $request->ip(),
            'approver_user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'customer_comment' => trim($data['comment']),
        ]));

        $notifier->notifyStore($proof);

        return $this->show($token);
    }

    private function find(string $token): ArtProof
    {
        return ArtProof::with(['files', 'company', 'quote.customer'])->where('token', $token)->firstOrFail();
    }

    /** Só responde uma vez: trava a linha pra duas abas não aprovarem e pedirem ajuste ao mesmo tempo. */
    private function respond(string $token, \Closure $fill): ArtProof
    {
        return DB::transaction(function () use ($token, $fill) {
            $proof = ArtProof::where('token', $token)->lockForUpdate()->firstOrFail();
            abort_unless($proof->isOpen(), 409, $proof->status === ArtProof::SUPERSEDED
                ? 'Esta versão da arte foi substituída por uma mais nova. Peça à loja o novo link.'
                : 'Esta arte já foi respondida.');

            $fill($proof);
            $proof->responded_at = now();
            $proof->save();

            return $proof->load(['company', 'quote.customer']);
        });
    }
}
