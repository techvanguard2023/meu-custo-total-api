<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use App\Models\QrCode;
use App\Models\QrCodeEvent;
use App\Models\Quote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QR Codes de divulgação da loja. O QR aponta para o link curto (/qr/{codigo} no domínio próprio, ou
 * /qr/{loja}/{codigo} no app); a função da Vercel (api/qr.js) pergunta aqui o destino, conta a leitura e
 * redireciona. Como o QR é nosso, o destino muda sem reimprimir.
 *
 * Vendas: o catálogo aberto pelo QR leva ?qr={codigo} (fica 30 dias no aparelho). O carrinho enviado pelo WhatsApp
 * conta como "carrinho" (com o valor) e a mensagem leva "QR: codigo" — o bot manda isso em origin_qr_code e o
 * pedido fica ligado ao QR.
 */
class QrCodes
{
    /** Robôs de prévia de link e buscadores: não contam como leitura. */
    public const BOTS = '/(facebookexternalhit|Facebot|WhatsApp|Twitterbot|Slackbot|LinkedInBot|TelegramBot|Discordbot|Pinterest|redditbot|Applebot|Googlebot|bingbot|bot\b|crawler|spider)/i';

    /** Tipos que contam como acesso (o carrinho é outra métrica). */
    public const ACCESS_KINDS = [QrCode::KIND_SCAN, QrCode::KIND_LINK];

    /** Link curto que vai no QR: no domínio próprio da loja quando ativo. */
    public function linkUrl(QrCode $qr): string
    {
        $company = $qr->company;
        $code = rawurlencode($qr->code);

        return $company->hasActiveCustomDomain()
            ? $company->publicOrigin()."/qr/{$code}"
            : $company->publicOrigin()."/qr/{$company->slug}/{$code}";
    }

    /** Loja do link curto: pelo slug (no app) ou pelo domínio próprio do catálogo. */
    public function resolveCompany(?string $slug, ?string $host): ?Company
    {
        if ($slug) {
            return Company::active()->where('slug', $slug)->first();
        }
        if ($host) {
            $company = Company::active()->where('custom_domain', Str::lower($host))->first();

            return $company?->hasActiveCustomDomain() ? $company : null;
        }

        return null;
    }

    /** Loja pelo endereço do catálogo (slug ou token antigo). */
    public function companyByCatalogToken(string $token): ?Company
    {
        return Company::active()->where(fn ($q) => $q->where('slug', $token)->orWhere('catalog_token', $token))->first();
    }

    public function find(Company $company, ?string $code): ?QrCode
    {
        $code = Str::lower(trim((string) $code));

        return $code === '' ? null : QrCode::where('company_id', $company->id)->whereRaw('LOWER(code) = ?', [$code])->first();
    }

    /** Para onde o QR leva agora; null quando o destino sumiu (produto apagado, catálogo desligado...). */
    public function destinationUrl(QrCode $qr): ?string
    {
        $company = $qr->company;
        $catalog = $company->hasCatalogActive() ? rtrim($company->catalogUrl(), '/') : null;

        $url = match ($qr->destination_type) {
            QrCode::DEST_CATALOG => $catalog,
            QrCode::DEST_PRODUCT => $catalog && ($slug = Product::where('company_id', $company->id)->where('active', true)->where('show_in_catalog', true)->whereKey($qr->destination_id)->value('slug'))
                ? "{$catalog}?p=".rawurlencode($slug) : null,
            QrCode::DEST_CATEGORY => $catalog ? $this->categoryUrl($company, $catalog, $qr->destination_id) : null,
            QrCode::DEST_COLLECTION => $catalog && ($slug = ProductCollection::where('company_id', $company->id)->where('active', true)->whereKey($qr->destination_id)->value('slug'))
                ? "{$catalog}/promo/".rawurlencode($slug) : null,
            // A mensagem leva o código do QR (como a do expositor): o bot liga o pedido a ele
            QrCode::DEST_WHATSAPP => $this->whatsappUrl($company, trim(trim((string) $qr->whatsapp_message)."\n\n(QR: {$qr->code})")),
            QrCode::DEST_INSTAGRAM => trim((string) $company->catalog_instagram_url) ?: null,
            QrCode::DEST_URL => trim((string) $qr->destination_url) ?: null,
            default => null,
        };

        // No catálogo: a origem (para as vendas) e o cupom, que já entra no carrinho
        if ($url && $this->isCatalogDestination($qr)) {
            $params = ['qr' => $qr->code];
            if ($coupon = $this->usableCoupon($qr)) {
                $params['cupom'] = $coupon->code;
            }
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($params);
        }

        return $url;
    }

    public function isCatalogDestination(QrCode $qr): bool
    {
        return in_array($qr->destination_type, [QrCode::DEST_CATALOG, QrCode::DEST_PRODUCT, QrCode::DEST_CATEGORY, QrCode::DEST_COLLECTION], true);
    }

    /** Cupom do QR, se ainda vale (ligado, dentro das datas, não é de um cliente só). O carrinho confere de novo. */
    public function usableCoupon(QrCode $qr): ?Coupon
    {
        if (! $qr->coupon_id) {
            return null;
        }
        $coupon = Coupon::where('company_id', $qr->company_id)->whereKey($qr->coupon_id)->first();
        $today = now()->toDateString();

        return $coupon && $coupon->active && ! $coupon->customer_id
            && (! $coupon->starts_on || $coupon->starts_on->toDateString() <= $today)
            && (! $coupon->ends_on || $coupon->ends_on->toDateString() >= $today)
            ? $coupon : null;
    }

    /** Destino sumiu ou QR pausado: o catálogo; sem catálogo, o Instagram ou o WhatsApp da loja. */
    public function fallbackUrl(Company $company): ?string
    {
        return ($company->hasCatalogActive() ? $company->catalogUrl() : null)
            ?: (trim((string) $company->catalog_instagram_url) ?: null)
            ?: $this->whatsappUrl($company, null);
    }

    public function whatsappUrl(Company $company, ?string $message): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $company->catalog_whatsapp);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) <= 11) {
            $digits = "55{$digits}";
        }
        $text = trim((string) $message);

        return "https://wa.me/{$digits}".($text !== '' ? '?text='.rawurlencode($text) : '');
    }

    private function categoryUrl(Company $company, string $catalog, ?int $categoryId): ?string
    {
        $category = $categoryId ? ProductCategory::visibleTo($company->id)->with('parent')->find($categoryId) : null;
        if (! $category || ! $category->slug) {
            return null;
        }

        $path = $category->parent?->slug ? "{$category->parent->slug}/{$category->slug}" : $category->slug;

        return "{$catalog}/{$path}";
    }

    public static function isBot(?string $userAgent): bool
    {
        return $userAgent === null || $userAgent === '' || (bool) preg_match(self::BOTS, $userAgent);
    }

    public static function device(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        return match (true) {
            (bool) preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|Android/i', $ua) => 'mobile',
            default => 'desktop',
        };
    }

    private function visitorHash(QrCode $qr, ?string $ip, ?string $userAgent): string
    {
        return hash('sha256', implode('|', [$ip, (string) $userAgent, now()->toDateString(), $qr->id, config('app.key')]));
    }

    /** Conta a leitura; a cidade é buscada depois da resposta, para o redirecionamento não esperar. */
    public function record(QrCode $qr, string $kind, ?string $ip, ?string $userAgent): void
    {
        $event = QrCodeEvent::create([
            'company_id' => $qr->company_id,
            'qr_code_id' => $qr->id,
            'kind' => $kind === QrCode::KIND_LINK ? QrCode::KIND_LINK : QrCode::KIND_SCAN,
            'visitor_hash' => $this->visitorHash($qr, $ip, $userAgent),
            'device' => self::device($userAgent),
            'created_at' => now(),
        ]);

        if ($ip) {
            dispatch(function () use ($event, $ip) {
                $location = VisitorLocator::resolve($ip);
                if ($location && ($location['city'] || $location['region'])) {
                    $event->forceFill(['city' => Str::limit((string) $location['city'], 80, ''), 'region' => Str::limit((string) $location['region'], 80, '')])->save();
                }
            })->afterResponse();
        }
    }

    /** Carrinho enviado pelo WhatsApp: um por pessoa por dia (reenviar atualiza o valor). */
    public function recordCart(QrCode $qr, float $amount, ?string $ip, ?string $userAgent): void
    {
        $hash = $this->visitorHash($qr, $ip, $userAgent);
        $existing = QrCodeEvent::where('qr_code_id', $qr->id)->where('kind', QrCode::KIND_CART)->where('visitor_hash', $hash)
            ->where('created_at', '>=', now()->startOfDay())->first();

        if ($existing) {
            $existing->forceFill(['amount' => round($amount, 2)])->save();

            return;
        }

        QrCodeEvent::create([
            'company_id' => $qr->company_id,
            'qr_code_id' => $qr->id,
            'kind' => QrCode::KIND_CART,
            'amount' => round($amount, 2),
            'visitor_hash' => $hash,
            'device' => self::device($userAgent),
            'created_at' => now(),
        ]);
    }

    /** Acessos dos últimos 30 dias e de sempre, por QR, e as vendas de 30 dias (para a lista). */
    public function summary(Company $company): array
    {
        $since = now()->subDays(30);

        $access = DB::table('qr_code_events')->where('company_id', $company->id)->whereIn('kind', self::ACCESS_KINDS)
            ->groupBy('qr_code_id')
            ->selectRaw('qr_code_id, COUNT(*) as total, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_30', [$since])
            ->get()->keyBy('qr_code_id');

        $orders = Quote::where('company_id', $company->id)->whereNotNull('origin_qr_code_id')
            ->where('status', Quote::STATUS_APPROVED)->where('created_at', '>=', $since)
            ->groupBy('origin_qr_code_id')
            ->selectRaw('origin_qr_code_id, COUNT(*) as orders, SUM(final_price) as revenue')
            ->get()->keyBy('origin_qr_code_id');

        $ids = $access->keys()->merge($orders->keys())->unique();

        return $ids->mapWithKeys(fn ($id) => [$id => [
            'total' => (int) ($access[$id]->total ?? 0),
            'last_30' => (int) ($access[$id]->last_30 ?? 0),
            'orders_30' => (int) ($orders[$id]->orders ?? 0),
            'revenue_30' => round((float) ($orders[$id]->revenue ?? 0), 2),
        ]])->all();
    }

    public function stats(QrCode $qr, int $days): array
    {
        $timezone = $qr->company->timezone ?: 'America/Sao_Paulo';
        $from = now($timezone)->subDays($days - 1)->startOfDay()->utc();
        $all = QrCodeEvent::where('qr_code_id', $qr->id)->where('created_at', '>=', $from)
            ->get(['kind', 'amount', 'visitor_hash', 'device', 'city', 'region', 'created_at']);
        $events = $all->whereIn('kind', self::ACCESS_KINDS);
        $carts = $all->where('kind', QrCode::KIND_CART);

        $byDay = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $byDay[now($timezone)->subDays($i)->toDateString()] = ['scans' => 0, 'links' => 0];
        }
        foreach ($events as $event) {
            $day = Carbon::parse($event->created_at)->timezone($timezone)->toDateString();
            if (isset($byDay[$day])) {
                $byDay[$day][$event->kind === QrCode::KIND_LINK ? 'links' : 'scans']++;
            }
        }

        $cities = $events->filter(fn ($e) => $e->city)
            ->groupBy(fn ($e) => $e->city.($e->region ? " — {$e->region}" : ''))
            ->map->count()->sortDesc()->take(8);

        $orders = Quote::where('origin_qr_code_id', $qr->id)->where('status', Quote::STATUS_APPROVED)->where('created_at', '>=', $from);

        return [
            'days' => $days,
            'total' => $events->count(),
            'unique' => $events->pluck('visitor_hash')->filter()->unique()->count(),
            'scans' => $events->where('kind', QrCode::KIND_SCAN)->count(),
            'links' => $events->where('kind', QrCode::KIND_LINK)->count(),
            'all_time' => QrCodeEvent::where('qr_code_id', $qr->id)->whereIn('kind', self::ACCESS_KINDS)->count(),
            'carts' => $carts->count(),
            'carts_total' => round((float) $carts->sum('amount'), 2),
            'orders' => (clone $orders)->count(),
            'revenue' => round((float) (clone $orders)->sum('final_price'), 2),
            'by_day' => collect($byDay)->map(fn ($v, $date) => ['date' => $date, ...$v])->values(),
            'devices' => [
                'mobile' => $events->where('device', 'mobile')->count(),
                'tablet' => $events->where('device', 'tablet')->count(),
                'desktop' => $events->where('device', 'desktop')->count(),
            ],
            'cities' => $cities->map(fn ($count, $name) => ['name' => $name, 'count' => $count])->values(),
        ];
    }
}
