<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\TestimonialController as PublicTestimonials;
use App\Http\Controllers\Controller;
use App\Models\PlatformTestimonial;
use App\Services\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/** Área admin → Opiniões → Depoimentos: aprovar (vai pra landing) ou esconder. O texto é da loja e não é editado. */
class TestimonialController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(PlatformTestimonial::STATUSES)]])['status'] ?? null;

        return PlatformTestimonial::with(['company:id,name,segment,logo_path,plan', 'user:id,name,email'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("status = 'pending' DESC")
            ->latest('updated_at')
            ->paginate(30)
            ->through(fn (PlatformTestimonial $t) => self::row($t));
    }

    public function update(Request $request, PlatformTestimonial $testimonial)
    {
        $data = $request->validate(['status' => ['required', Rule::in(PlatformTestimonial::STATUSES)]]);
        $testimonial->update(['status' => $data['status'], 'reviewed_at' => $data['status'] === PlatformTestimonial::STATUS_PENDING ? null : now()]);
        Cache::forget(PublicTestimonials::PUBLIC_CACHE_KEY);
        AdminAudit::log($request, 'testimonial.status', $testimonial->company_id, $testimonial->user_id, ['testimonial_id' => $testimonial->id, 'status' => $data['status']]);

        return self::row($testimonial->load(['company:id,name,segment,logo_path,plan', 'user:id,name,email']));
    }

    public static function row(PlatformTestimonial $t): array
    {
        return [
            'id' => $t->id,
            'text' => $t->text,
            'author_name' => $t->author_name,
            'status' => $t->status,
            'consent_at' => $t->consent_at?->toIso8601String(),
            'reviewed_at' => $t->reviewed_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
            'company' => $t->company ? ['id' => $t->company->id, 'name' => $t->company->name, 'segment' => $t->company->segment, 'logo_url' => $t->company->logo_url] : null,
            'user' => $t->user ? ['name' => $t->user->name, 'email' => $t->user->email] : null,
        ];
    }
}
