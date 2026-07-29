<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\CatalogBanner;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Configuração do catálogo público (recurso Pro) — liga/desliga e gera o
 * token compartilhável. A exibição do catálogo em si é feita pelo
 * PublicCatalogController, sem autenticação.
 */
class CatalogController extends Controller
{
    use EnforcesPlanLimits;

    private const MAX_BANNERS = 3;

    public function show(Request $request)
    {
        return response()->json($this->payload($request->user()->company));
    }

    public function update(Request $request)
    {
        $this->requirePro($request, 'Catálogo público');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'disclaimer' => ['nullable', 'string', 'max:1000'],
            'instagram_url' => ['nullable', 'url', 'max:2048'],
            'facebook_url' => ['nullable', 'url', 'max:2048'],
            'youtube_url' => ['nullable', 'url', 'max:2048'],
            'tiktok_url' => ['nullable', 'url', 'max:2048'],
            'linkedin_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $company = $request->user()->company;

        if ($data['enabled'] && ! $company->catalog_token) {
            $company->catalog_token = $this->generateToken();
        }

        $company->catalog_enabled = $data['enabled'];
        if (array_key_exists('whatsapp', $data)) {
            $company->catalog_whatsapp = $data['whatsapp'];
        }
        if (array_key_exists('disclaimer', $data)) {
            $company->catalog_disclaimer = $data['disclaimer'];
        }
        foreach (['instagram', 'facebook', 'youtube', 'tiktok', 'linkedin'] as $network) {
            if (array_key_exists("{$network}_url", $data)) {
                $company->{"catalog_{$network}_url"} = $data["{$network}_url"];
            }
        }
        $company->save();

        return response()->json($this->payload($company));
    }

    /** Gera um novo link, invalidando o anterior (útil se o link vazou). */
    public function regenerate(Request $request)
    {
        $this->requirePro($request, 'Catálogo público');

        $company = $request->user()->company;
        $company->update(['catalog_token' => $this->generateToken()]);

        return response()->json($this->payload($company));
    }

    /** Logo exibida no cabeçalho do catálogo público — opcional. */
    public function uploadLogo(Request $request)
    {
        $this->requirePro($request, 'Catálogo público');

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $company = $request->user()->company;

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }

        $path = $request->file('logo')->store('logos', 'public');
        $company->update(['logo_path' => $path]);

        return response()->json($this->payload($company->fresh()));
    }

    public function destroyLogo(Request $request)
    {
        $company = $request->user()->company;

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $company->update(['logo_path' => null]);
        }

        return response()->json($this->payload($company->fresh()));
    }

    /** Banner de anúncio no topo do catálogo público — carrossel de até 3. */
    public function uploadBanner(Request $request)
    {
        $this->requirePro($request, 'Catálogo público');

        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'link_url' => ['nullable', 'url', 'max:2048'],
        ], [
            'image.max' => 'A imagem deve ter no máximo 4MB.',
            'image.image' => 'Envie um arquivo de imagem válido (JPG, PNG ou WebP).',
            'image.mimes' => 'Formato não suportado — envie JPG, PNG ou WebP.',
        ]);

        $company = $request->user()->company;
        $existingCount = $company->banners()->count();
        abort_if($existingCount >= self::MAX_BANNERS, 422, 'Máximo de '.self::MAX_BANNERS.' banners.');

        $path = $request->file('image')->store('banners', 'public');
        $company->banners()->create([
            'image_path' => $path,
            'link_url' => $data['link_url'] ?? null,
            'position' => $existingCount,
        ]);

        return response()->json($this->payload($company->fresh()));
    }

    public function updateBanner(Request $request, CatalogBanner $banner)
    {
        $this->requirePro($request, 'Catálogo público');
        abort_unless($banner->company_id === $request->user()->company_id, 403);

        $data = $request->validate([
            'link_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $banner->update(['link_url' => $data['link_url'] ?? null]);

        return response()->json($this->payload($request->user()->company->fresh()));
    }

    public function destroyBanner(Request $request, CatalogBanner $banner)
    {
        abort_unless($banner->company_id === $request->user()->company_id, 403);

        Storage::disk('public')->delete($banner->image_path);
        $banner->delete();

        return response()->json($this->payload($request->user()->company->fresh()));
    }

    /** Reordena os banners — recebe a lista de IDs na nova ordem. */
    public function reorderBanners(Request $request)
    {
        $company = $request->user()->company;

        $data = $request->validate([
            'banner_ids' => ['required', 'array'],
            'banner_ids.*' => ['integer'],
        ]);

        $ids = $company->banners()->pluck('id')->all();
        abort_unless(count(array_diff($ids, $data['banner_ids'])) === 0 && count($data['banner_ids']) === count($ids), 422, 'Lista de banners inválida.');

        foreach ($data['banner_ids'] as $position => $bannerId) {
            CatalogBanner::where('id', $bannerId)->where('company_id', $company->id)->update(['position' => $position]);
        }

        return response()->json($this->payload($company->fresh()));
    }

    private function payload(Company $company): array
    {
        return [
            'enabled' => $company->catalog_enabled,
            'token' => $company->catalog_token,
            'logo_url' => $company->logo_url,
            'whatsapp' => $company->catalog_whatsapp,
            'disclaimer' => $company->catalog_disclaimer,
            'social' => [
                'instagram_url' => $company->catalog_instagram_url,
                'facebook_url' => $company->catalog_facebook_url,
                'youtube_url' => $company->catalog_youtube_url,
                'tiktok_url' => $company->catalog_tiktok_url,
                'linkedin_url' => $company->catalog_linkedin_url,
            ],
            'banners' => $company->banners->map(fn ($banner) => [
                'id' => $banner->id,
                'image_url' => $banner->image_url,
                'link_url' => $banner->link_url,
            ]),
        ];
    }

    private function generateToken(): string
    {
        return Str::random(32);
    }
}
