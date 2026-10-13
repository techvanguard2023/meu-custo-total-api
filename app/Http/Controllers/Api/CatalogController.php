<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\CatalogBanner;
use App\Models\CatalogVisit;
use App\Models\Company;
use App\Services\VercelDomains;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $this->requireEssential($request, 'Catálogo público');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'disclaimer' => ['nullable', 'string', 'max:1000'],
            'about' => ['nullable', 'string', 'max:2000'],
            'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'hours' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
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
        foreach (['about', 'address', 'hours', 'email', 'accent_color'] as $field) {
            if (array_key_exists($field, $data)) {
                $company->{"catalog_{$field}"} = $data[$field];
            }
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
        $this->requireEssential($request, 'Catálogo público');

        $company = $request->user()->company;
        $company->update(['catalog_token' => $this->generateToken()]);

        return response()->json($this->payload($company));
    }

    /**
     * Endereço do catálogo (/catalog/{slug}) — editável pelo dono da empresa, ao
     * contrário do sufixo aleatório gerado no cadastro. Disponível pra qualquer
     * plano: é a identidade da empresa, não um recurso do catálogo em si.
     *
     * Trocar o slug quebra qualquer link já compartilhado com o endereço antigo
     * (o front avisa disso antes de confirmar) — não há redirecionamento do
     * endereço velho pro novo.
     */
    public function updateSlug(Request $request)
    {
        $company = $request->user()->company;

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:80'],
        ]);

        $slug = Str::slug($data['slug']);

        abort_if(strlen($slug) < 3, 422, 'O endereço deve ter pelo menos 3 caracteres.');
        abort_if(strlen($slug) > 60, 422, 'O endereço deve ter no máximo 60 caracteres.');

        // Confere contra slug e contra catalog_token de outras empresas — o
        // resolvedor público aceita os dois, então os dois precisam ficar livres
        // pra não um novo endereço acidentalmente abrir o catálogo de outra loja.
        $taken = Company::where('id', '!=', $company->id)
            ->where(fn ($q) => $q->where('slug', $slug)->orWhere('catalog_token', $slug))
            ->exists();
        abort_if($taken, 422, 'Esse endereço já está em uso por outra empresa.');

        $company->update(['slug' => $slug]);

        return response()->json([
            'message' => 'Endereço do catálogo atualizado!',
            'user' => $request->user()->fresh()->load('company'),
        ]);
    }

    /** Logo exibida no cabeçalho do catálogo público — opcional. */
    public function uploadLogo(Request $request)
    {
        $this->requireEssential($request, 'Catálogo público');

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
        $this->requireEssential($request, 'Catálogo público');

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
        $this->requireEssential($request, 'Catálogo público');
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

    /** Métricas de acesso ao catálogo público (recurso Pro). */
    public function analytics(Request $request)
    {
        $this->requirePro($request, 'Métricas do catálogo');

        $companyId = $request->user()->company_id;
        $today = CarbonImmutable::today();
        $timelineDays = 30;
        $timelineStart = $today->subDays($timelineDays - 1);

        // Uma única leitura cobre tanto os totais quanto o gráfico: agrega por dia e
        // soma depois em memória, evitando várias queries repetidas sobre a mesma faixa.
        // Precisa alcançar os 30 dias anteriores para o comparativo de tendência.
        $comparisonStart = $today->subDays($timelineDays * 2 - 1);

        $daily = CatalogVisit::query()
            ->where('company_id', $companyId)
            ->where('visit_date', '>=', $comparisonStart->toDateString())
            ->groupBy('visit_date')
            ->orderBy('visit_date')
            ->get([
                'visit_date',
                DB::raw('COUNT(*) as page_views'),
                DB::raw('COUNT(DISTINCT visitor_hash) as unique_visitors'),
            ])
            ->keyBy(fn ($row) => CarbonImmutable::parse($row->visit_date)->toDateString());

        $sumRange = function (CarbonImmutable $from, CarbonImmutable $to) use ($daily): array {
            $views = 0;
            $unique = 0;
            foreach ($daily as $date => $row) {
                if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                    $views += (int) $row->page_views;
                    $unique += (int) $row->unique_visitors;
                }
            }

            return ['page_views' => $views, 'unique_visitors' => $unique];
        };

        // Série diária contínua — dias sem acesso entram como zero para o gráfico
        // não "pular" datas e distorcer a leitura dos picos.
        $timeline = [];
        for ($i = 0; $i < $timelineDays; $i++) {
            $date = $timelineStart->addDays($i);
            $key = $date->toDateString();
            $row = $daily->get($key);

            $timeline[] = [
                'date' => $key,
                'label' => $date->format('d/m'),
                'page_views' => (int) ($row->page_views ?? 0),
                'unique_visitors' => (int) ($row->unique_visitors ?? 0),
            ];
        }

        $last7 = $sumRange($today->subDays(6), $today);
        $previous7 = $sumRange($today->subDays(13), $today->subDays(7));
        $last30 = $sumRange($timelineStart, $today);
        $previous30 = $sumRange($comparisonStart, $timelineStart->subDay());

        // De onde vêm os acessos (30 dias). Visitas sem localização — IP de rede
        // local ou serviço indisponível na hora — ficam de fora em vez de virar
        // uma linha "Desconhecido" que não ajuda em nada.
        $topLocations = CatalogVisit::query()
            ->where('company_id', $companyId)
            ->where('visit_date', '>=', $timelineStart->toDateString())
            ->whereNotNull('city')
            ->groupBy('city', 'region', 'country_code')
            ->orderByDesc(DB::raw('COUNT(DISTINCT visitor_hash)'))
            ->limit(8)
            ->get([
                'city',
                'region',
                'country_code',
                DB::raw('COUNT(DISTINCT visitor_hash) as unique_visitors'),
                DB::raw('COUNT(*) as page_views'),
            ])
            ->map(fn ($row) => [
                'city' => $row->city,
                'region' => $row->region,
                'country_code' => $row->country_code,
                'label' => $row->region ? "{$row->city}/{$row->region}" : $row->city,
                'unique_visitors' => (int) $row->unique_visitors,
                'page_views' => (int) $row->page_views,
            ]);

        return response()->json([
            'today' => $sumRange($today, $today),
            'last_7_days' => array_merge($last7, [
                'unique_change_percent' => $this->changePercent($previous7['unique_visitors'], $last7['unique_visitors']),
            ]),
            'last_30_days' => array_merge($last30, [
                'unique_change_percent' => $this->changePercent($previous30['unique_visitors'], $last30['unique_visitors']),
            ]),
            'timeline' => $timeline,
            'top_locations' => $topLocations,
        ]);
    }

    /**
     * Variação percentual entre dois períodos. Devolve null quando não há base de
     * comparação (período anterior zerado), pra tela não mostrar "+100%" enganoso
     * em quem acabou de ativar o catálogo.
     */
    private function changePercent(int $previous, int $current): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /* ---------- Domínio próprio (Pro) ---------- */

    public function domainShow(Request $request, VercelDomains $vercel)
    {
        return response()->json($this->domainPayload($request->user()->company, $vercel, refresh: true));
    }

    public function domainUpdate(Request $request, VercelDomains $vercel)
    {
        $this->requirePro($request, 'Domínio próprio');
        abort_unless($vercel->isConfigured(), 503, 'Domínio próprio ainda não está disponível. Fale com o suporte.');

        $company = $request->user()->company;
        $domain = $this->normalizeDomain((string) $request->input('domain'));

        abort_if(
            Company::where('custom_domain', $domain)->where('id', '!=', $company->id)->exists(),
            422,
            'Esse domínio já está em uso por outra loja.'
        );

        if ($company->custom_domain && $company->custom_domain !== $domain) {
            $vercel->remove($company->custom_domain);
        }

        $result = $vercel->add($domain);
        abort_unless($result['ok'], 422, $result['error']);

        $company->forceFill(['custom_domain' => $domain, 'custom_domain_verified_at' => null])->save();

        return response()->json($this->domainPayload($company, $vercel, refresh: true));
    }

    /** Reconsulta a Vercel (útil logo depois de configurar o DNS, que pode levar alguns minutos). */
    public function domainVerify(Request $request, VercelDomains $vercel)
    {
        $this->requirePro($request, 'Domínio próprio');

        $company = $request->user()->company;
        abort_unless($company->custom_domain, 422, 'Nenhum domínio cadastrado.');

        $vercel->verify($company->custom_domain);

        return response()->json($this->domainPayload($company, $vercel, refresh: true));
    }

    public function domainDestroy(Request $request, VercelDomains $vercel)
    {
        $company = $request->user()->company;

        if ($company->custom_domain) {
            if ($vercel->isConfigured()) {
                $vercel->remove($company->custom_domain);
            }
            $company->forceFill(['custom_domain' => null, 'custom_domain_verified_at' => null])->save();
        }

        return response()->json($this->domainPayload($company, $vercel));
    }

    private function domainPayload(Company $company, VercelDomains $vercel, bool $refresh = false): array
    {
        $domain = $company->custom_domain;
        $status = ['active' => false, 'verified' => false, 'misconfigured' => true, 'cname' => VercelDomains::DEFAULT_CNAME, 'verification' => []];

        if ($domain && $refresh && $vercel->isConfigured() && $company->isPro()) {
            try {
                $status = $vercel->status($domain);
                if ($status['active'] && ! $company->custom_domain_verified_at) {
                    $company->forceFill(['custom_domain_verified_at' => now()])->save();
                }
            } catch (\Throwable $e) {
                // Vercel fora do ar: mostra o que já se sabe, sem quebrar a tela
            }
        }

        $labels = explode('.', (string) $domain);
        // Parte do endereço que vai no campo "Nome/Host" do DNS: tudo antes do domínio registrado (loja de loja.empresa.com.br)
        $registrable = count($labels) >= 3 && in_array($labels[count($labels) - 2], self::SECOND_LEVEL_LABELS, true) ? 3 : 2;
        $hostLabel = $domain ? implode('.', array_slice($labels, 0, max(1, count($labels) - $registrable))) : null;

        return [
            'available' => $vercel->isConfigured(),
            'domain' => $domain,
            'status' => ! $domain ? 'none' : ($company->custom_domain_verified_at ? 'active' : 'pending'),
            'url' => $company->hasActiveCustomDomain() ? $company->catalogUrl() : null,
            'dns' => $domain ? ['type' => 'CNAME', 'name' => $hostLabel, 'value' => $status['cname']] : null,
            'verification' => $status['verification'],
        ];
    }

    /** Segundo nível comum sob TLDs de país (empresa.com.br) — pra achar onde começa o domínio registrado. */
    private const SECOND_LEVEL_LABELS = ['com', 'net', 'org', 'gov', 'edu', 'mil', 'art', 'eco', 'tec', 'esp', 'far', 'imb', 'ind', 'inf', 'psi', 'rec', 'srv', 'tur', 'blog', 'app'];

    /**
     * Aceita só subdomínio (loja.empresa.com.br): o domínio raiz exige configuração de DNS mais difícil
     * e costuma já estar em uso pelo site da empresa.
     */
    private function normalizeDomain(string $input): string
    {
        $domain = strtolower(trim($input));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim(explode('/', $domain)[0], '.');

        $valid = (bool) preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain);
        abort_unless($valid, 422, 'Informe um endereço válido, como loja.suaempresa.com.br.');

        $labels = explode('.', $domain);
        $isApex = count($labels) < 3 || (count($labels) === 3 && in_array($labels[1], self::SECOND_LEVEL_LABELS, true));
        abort_if($isApex, 422, 'Use um subdomínio, como loja.suaempresa.com.br (e não o domínio principal).');

        abort_if(
            Str::endsWith($domain, ['meucustototal.com.br', '.vercel.app', '.localhost']),
            422,
            'Esse endereço não pode ser usado. Use um domínio seu.'
        );

        return $domain;
    }

    private function payload(Company $company): array
    {
        return [
            'enabled' => $company->catalog_enabled,
            'token' => $company->catalog_token,
            'logo_url' => $company->logo_url,
            'whatsapp' => $company->catalog_whatsapp,
            'disclaimer' => $company->catalog_disclaimer,
            'about' => $company->catalog_about,
            'accent_color' => $company->catalog_accent_color,
            'address' => $company->catalog_address,
            'hours' => $company->catalog_hours,
            'email' => $company->catalog_email,
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
