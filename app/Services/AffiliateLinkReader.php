<?php

namespace App\Services;

use App\Models\AffiliateOffer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Lê o link de afiliado colado e tenta preencher o achadinho: título, foto, descrição e (quando a página
 * informa de forma confiável) o preço. Pede a página como o leitor de links do WhatsApp — é o que as lojas
 * respondem com a prévia (título e foto). Testado em 2026-11-04: Mercado Livre e Amazon trazem título e foto;
 * Shopee monta a página por JavaScript, então só o nome que vem no endereço. Preço quase nunca vem: a pessoa confere.
 *
 * O endereço vem do usuário: só http(s) e nunca rede interna (inclusive depois de cada redirecionamento).
 */
class AffiliateLinkReader
{
    private const USER_AGENT = 'WhatsApp/2.23.20.0';

    private const MAX_PAGE_BYTES = 3_000_000;

    private const MAX_IMAGE_BYTES = 5_000_000;

    /** Títulos genéricos (página de bloqueio/verificação ou a home): não servem. */
    private const GENERIC_TITLES = ['mercado livre', 'mercado libre', 'shopee', 'shopee brasil', 'amazon', 'amazon.com.br', 'magalu', 'aliexpress', 'shein'];

    /**
     * @return array{marketplace: string, final_url: string, title: ?string, image_url: ?string, description: ?string, price: ?float}
     */
    public function read(string $url): array
    {
        $url = trim($url);
        $this->assertPublicUrl($url);

        $finalUrl = $url;
        $html = '';
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'pt-BR,pt;q=0.9',
            ])
                ->timeout(10)
                ->withOptions([
                    // Página gigante não vale a espera: corta passando do limite
                    'progress' => function ($total, $downloaded) {
                        if ($downloaded > self::MAX_PAGE_BYTES) {
                            throw new \RuntimeException('Página grande demais');
                        }
                    },
                    'allow_redirects' => [
                        'max' => 6,
                        'track_redirects' => true,
                        // Cada salto do link curto (amzn.to, s.shopee...) também tem que ser endereço público
                        'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use (&$finalUrl) {
                            $this->assertPublicUrl((string) $uri);
                            $finalUrl = (string) $uri;
                        },
                    ],
                ])
                ->get($url);

            $html = $response->body();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::info('Achadinhos: não deu pra ler o link ('.parse_url($url, PHP_URL_HOST).'): '.Str::limit($e->getMessage(), 200));
        }

        $marketplace = AffiliateOffer::detectMarketplace($finalUrl !== $url ? $finalUrl : $url);
        if ($marketplace === 'other') {
            $marketplace = AffiliateOffer::detectMarketplace($url);
        }

        // Shopee: a página não traz o produto, mas o endereço final tem o nome ("Fone-Bluetooth-XYZ-i.123.456")
        $title = preg_match('~/([^/?#]+)-i\.\d+\.\d+~', $finalUrl, $m)
            ? Str::limit(trim(str_replace('-', ' ', urldecode($m[1]))), 200, '')
            : null;
        if (! $title) {
            $title = $this->meta($html, 'og:title') ?? $this->tag($html, 'title');
            $title = $title ? $this->cleanTitle($title) : null;
        }

        // Amazon: a foto principal grande (a do og:image é um banner recortado)
        $image = $this->attr($html, 'data-old-hires') ?: $this->meta($html, 'og:image');

        return [
            'marketplace' => $marketplace,
            'final_url' => $finalUrl,
            'title' => $title ?: null,
            'image_url' => $image && str_starts_with($image, 'http') ? $image : null,
            'description' => ($d = $this->meta($html, 'og:description')) ? Str::limit($d, 300) : null,
            'price' => $this->price($html),
        ];
    }

    /** Baixa a foto (da prévia do link) pro disco do sistema — a oferta sai com a imagem, não com o link dela. */
    public function downloadImage(string $imageUrl, int $companyId): ?string
    {
        try {
            $this->assertPublicUrl($imageUrl);
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(15)
                ->withOptions(['allow_redirects' => ['max' => 3, 'on_redirect' => fn ($req, $res, UriInterface $uri) => $this->assertPublicUrl((string) $uri)]])
                ->get($imageUrl);
            $bytes = $response->successful() ? $response->body() : '';
        } catch (Throwable $e) {
            Log::info('Achadinhos: foto não baixada: '.Str::limit($e->getMessage(), 200));

            return null;
        }

        return $this->storeBytes($bytes, $companyId);
    }

    public function storeUpload(UploadedFile $file, int $companyId): ?string
    {
        return $this->storeBytes((string) file_get_contents($file->getRealPath()), $companyId);
    }

    /** Só imagem de verdade (JPEG, PNG ou WebP), até 5 MB. */
    private function storeBytes(string $bytes, int $companyId): ?string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        $ext = match ($info['mime'] ?? null) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if (! $ext) {
            return null;
        }

        $path = "affiliate/{$companyId}/".Str::random(32).".{$ext}";
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /** Recusa endereço que não seja http(s) público — senão o servidor viraria ponte pra rede interna. */
    public function assertPublicUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        $fail = fn () => throw ValidationException::withMessages(['url' => 'Cole o link completo do produto (começando com https://).']);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || in_array($parts['port'] ?? null, [22, 25, 3306, 6379], true)) {
            $fail();
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            throw ValidationException::withMessages(['url' => 'Não encontramos esse endereço. Confira o link.']);
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $fail();
            }
        }
    }

    private function meta(string $html, string $property): ?string
    {
        $p = preg_quote($property, '~');
        if (preg_match('~<meta[^>]+(?:property|name)=["\']'.$p.'["\'][^>]*content=["\']([^"\']*)["\']~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']'.$p.'["\']~i', $html, $m)) {
            $value = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function tag(string $html, string $tag): ?string
    {
        return preg_match('~<'.$tag.'[^>]*>([^<]{1,300})</'.$tag.'>~i', $html, $m)
            ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
    }

    private function attr(string $html, string $attribute): ?string
    {
        return preg_match('~'.preg_quote($attribute, '~').'=["\']([^"\']+)["\']~i', $html, $m) ? $m[1] : null;
    }

    /** Tira o "| Amazon.com.br", ": Amazon.com.br: Eletrônicos", "| Mercado Livre" do fim; ignora títulos genéricos. */
    private function cleanTitle(string $title): ?string
    {
        $title = preg_replace('~\s*[|:–-]\s*(Amazon\.com\.br|Mercado Livre|MercadoLivre|Shopee Brasil|Shopee|Magalu|AliExpress|SHEIN)\b.*$~iu', '', $title);
        $title = trim(preg_replace('/\s+/', ' ', (string) $title));

        $generic = in_array(mb_strtolower($title), self::GENERIC_TITLES, true)
            || preg_match('~^(Shopee Brasil|Mercado Livre|Amazon\.com\.br)\b~i', $title);

        return $title === '' || $generic ? null : Str::limit($title, 200, '');
    }

    /** Preço só quando a página declara de forma estruturada (JSON-LD ou meta de produto). */
    private function price(string $html): ?float
    {
        $candidates = [];
        if (preg_match('~<meta[^>]+(?:property|name)=["\'](?:product:price:amount|og:price:amount)["\'][^>]*content=["\']([\d.,]+)["\']~i', $html, $m)) {
            $candidates[] = $m[1];
        }
        if (preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~is', $html, $blocks)) {
            foreach ($blocks[1] as $json) {
                if (preg_match('~"(?:price|lowPrice)"\s*:\s*"?([\d.,]+)"?~', $json, $m)) {
                    $candidates[] = $m[1];
                }
            }
        }

        foreach ($candidates as $raw) {
            // "1.299,90" → 1299.90; "1299.90" → 1299.90
            $normalized = str_contains($raw, ',') ? str_replace(['.', ','], ['', '.'], $raw) : $raw;
            if (is_numeric($normalized) && (float) $normalized > 0) {
                return round((float) $normalized, 2);
            }
        }

        return null;
    }
}
