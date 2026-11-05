<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imagens enviadas viram arquivos leves: na orientação certa (foto de celular "deitada" é endireitada pelo
 * EXIF), sem os metadados da foto (inclusive GPS), no tamanho máximo do uso e no formato certo pra cada lugar:
 *
 *  - WebP onde só o app e o catálogo mostram (banners, coleções, fotos de visita): bem menor que JPEG;
 *  - JPEG/PNG onde a imagem sai do app — prévia de link do WhatsApp, envio de ofertas, cartões — já que o
 *    WebP não é aceito em todo lugar (logo, achadinhos e a cópia de compartilhamento das fotos de produto).
 *
 * Medido em 2026-11-04 com imagens reais: banner de 1,7 MB → 126 KB (WebP); logo de 611 KB → 76 KB (JPEG).
 * Se o GD não conseguir abrir o arquivo, guarda o original (nunca perde o envio).
 */
class ImageOptimizer
{
    /** [lado maior em px, formato (webp|jpeg|auto = png com transparência, senão jpeg), qualidade] */
    public const PRESETS = [
        'product' => [1600, 'webp', 80],
        'product_share' => [1200, 'jpeg', 82],
        'banner' => [1920, 'webp', 80],
        'collection' => [1920, 'webp', 80],
        'logo' => [600, 'auto', 88],
        'visit' => [1600, 'webp', 78],
        'affiliate' => [1200, 'jpeg', 82],
    ];

    /** Guarda o arquivo enviado já otimizado em $dir. Devolve o caminho no disco público. */
    public function store(UploadedFile|string $source, string $dir, string $preset): string
    {
        $bytes = $source instanceof UploadedFile ? (string) file_get_contents($source->getRealPath()) : $source;
        $optimized = $this->optimize($bytes, $preset);

        if (! $optimized) {
            // Não deu pra abrir com o GD: guarda como veio
            $ext = $source instanceof UploadedFile ? ($source->guessExtension() ?: 'jpg') : 'jpg';
            $path = trim($dir, '/').'/'.Str::random(40).'.'.$ext;
            Storage::disk('public')->put($path, $bytes);

            return $path;
        }

        $path = trim($dir, '/').'/'.Str::random(40).'.'.$optimized['ext'];
        Storage::disk('public')->put($path, $optimized['bytes']);

        return $path;
    }

    /**
     * Foto de produto: WebP pro app/catálogo e uma cópia JPEG leve com o mesmo nome (prévia de link e envio
     * no WhatsApp). @return array{path: string, share_path: ?string}
     */
    public function storeProductImage(UploadedFile|string $source, string $dir = 'products'): array
    {
        $bytes = $source instanceof UploadedFile ? (string) file_get_contents($source->getRealPath()) : $source;
        $main = $this->optimize($bytes, 'product');
        $share = $this->optimize($bytes, 'product_share');

        if (! $main) {
            return ['path' => $this->store($source, $dir, 'product'), 'share_path' => null];
        }

        $base = trim($dir, '/').'/'.Str::random(40);
        Storage::disk('public')->put("{$base}.{$main['ext']}", $main['bytes']);
        $sharePath = null;
        if ($share) {
            $sharePath = "{$base}.share.{$share['ext']}";
            Storage::disk('public')->put($sharePath, $share['bytes']);
        }

        return ['path' => "{$base}.{$main['ext']}", 'share_path' => $sharePath];
    }

    /** @return array{bytes: string, ext: string, width: int, height: int}|null */
    public function optimize(string $bytes, string $preset): ?array
    {
        [$max, $format, $quality] = self::PRESETS[$preset];

        try {
            $image = @imagecreatefromstring($bytes);
            if (! $image) {
                return null;
            }

            $image = $this->orient($image, $bytes);
            $image = $this->fit($image, $max);
            if ($format === 'auto') {
                $format = $this->hasTransparency($image) ? 'png' : 'jpeg';
            }

            ob_start();
            match ($format) {
                'webp' => imagewebp($image, null, $quality),
                'png' => $this->png($image),
                default => $this->jpeg($image, $quality),
            };
            $out = (string) ob_get_clean();

            return $out === '' ? null : [
                'bytes' => $out,
                'ext' => $format === 'jpeg' ? 'jpg' : $format,
                'width' => imagesx($image),
                'height' => imagesy($image),
            ];
        } catch (Throwable $e) {
            Log::info('Imagem não otimizada: '.Str::limit($e->getMessage(), 200));

            return null;
        }
    }

    /** Versão JPEG de uma imagem guardada (o WhatsApp não aceita WebP em todo lugar). */
    public function jpegFromPath(string $path, int $max = 1200): ?string
    {
        if (! Storage::disk('public')->exists($path)) {
            return null;
        }
        $image = @imagecreatefromstring(Storage::disk('public')->get($path));
        if (! $image) {
            return null;
        }

        ob_start();
        $this->jpeg($this->fit($image, $max), 82);

        return (string) ob_get_clean() ?: null;
    }

    /** Foto de celular vem "deitada" com a orientação no EXIF: gira de verdade antes de jogar o EXIF fora. */
    private function orient(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        return $rotated ?: $image;
    }

    private function fit(GdImage $image, int $max): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        if (max($w, $h) <= $max) {
            return $this->truecolor($image);
        }

        $scale = $max / max($w, $h);
        $target = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $w, $h);

        return $target;
    }

    /** PNG com paleta (8 bits) vira truecolor: o WebP/JPEG do GD precisam disso. */
    private function truecolor(GdImage $image): GdImage
    {
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /** Algum pixel transparente? (amostra de até ~10 mil pontos — rápido mesmo em logo grande) */
    private function hasTransparency(GdImage $image): bool
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $step = max(1, (int) floor(sqrt(($w * $h) / 10000)));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** JPEG não tem transparência: fundo branco no lugar (senão vira preto). */
    private function jpeg(GdImage $image, int $quality): void
    {
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imageinterlace($flat, true); // progressivo: aparece aos poucos em conexão lenta
        imagejpeg($flat, null, $quality);
    }

    private function png(GdImage $image): void
    {
        imagesavealpha($image, true);
        imagepng($image, null, 9);
    }
}
