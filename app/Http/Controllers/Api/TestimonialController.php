<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformTestimonial;
use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Depoimento da loja sobre o Meu Custo Total (só o dono; um por loja). Escrever ou mudar o texto volta pra
 * aprovação. Apagar tira da landing na hora. A landing lê os aprovados em GET /public/testimonials.
 */
class TestimonialController extends Controller
{
    public const PUBLIC_CACHE_KEY = 'public-testimonials';

    public function show(Request $request)
    {
        $t = PlatformTestimonial::where('company_id', $request->user()->company_id)->first();

        return ['testimonial' => $t ? self::own($t) : null, 'consent_text' => PlatformTestimonial::CONSENT_TEXT];
    }

    public function save(Request $request)
    {
        abort_if(AdminAudit::impersonatorId($request->user()->currentAccessToken()) !== null, 403, 'Você está dentro da loja pela área admin — o depoimento é do lojista.');

        $data = $request->validate([
            'text' => ['required', 'string', 'min:20', 'max:600'],
            'author_name' => ['required', 'string', 'min:2', 'max:80'],
            'consent' => ['accepted'],
        ], [
            'text.required' => 'Escreva o depoimento.',
            'text.min' => 'Conte um pouco mais (pelo menos 20 letras).',
            'text.max' => 'O depoimento pode ter até 600 letras.',
            'author_name.*' => 'Diga como quer aparecer (ex: seu primeiro nome).',
            'consent.accepted' => 'Para publicar, marque a autorização.',
        ]);

        $user = $request->user();
        $t = PlatformTestimonial::updateOrCreate(['company_id' => $user->company_id], [
            'user_id' => $user->id,
            'text' => trim($data['text']),
            'author_name' => trim($data['author_name']),
            'status' => PlatformTestimonial::STATUS_PENDING,
            'consent_at' => now(),
            'reviewed_at' => null,
        ]);
        Cache::forget(self::PUBLIC_CACHE_KEY);

        return ['testimonial' => self::own($t)];
    }

    public function destroy(Request $request)
    {
        PlatformTestimonial::where('company_id', $request->user()->company_id)->delete();
        Cache::forget(self::PUBLIC_CACHE_KEY);

        return response()->noContent();
    }

    /** Landing: depoimentos aprovados (mais recentes primeiro), com o nome e o logo da loja. */
    public function publicIndex()
    {
        return Cache::remember(self::PUBLIC_CACHE_KEY, now()->addMinutes(10), fn () => PlatformTestimonial::with('company:id,name,segment,logo_path')
            ->where('status', PlatformTestimonial::STATUS_APPROVED)
            ->whereHas('company', fn ($q) => $q->whereNull('blocked_at')->whereNull('deletion_scheduled_for'))
            ->latest('reviewed_at')
            ->limit(12)
            ->get()
            ->map(fn (PlatformTestimonial $t) => [
                'id' => $t->id,
                'text' => $t->text,
                'author' => $t->author_name,
                'shop' => $t->company->name,
                'segment' => $t->company->segment,
                'logo_url' => $t->company->logo_url,
            ])->values()->all());
    }

    private static function own(PlatformTestimonial $t): array
    {
        return [
            'text' => $t->text,
            'author_name' => $t->author_name,
            'status' => $t->status,
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}
