<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

/**
 * Base dos cartões (1080x1080) que vão como imagem nas mensagens de WhatsApp da loja — cashback,
 * convite da área do parceiro. Fundo na cor do catálogo, logo no topo, texto com a fonte Poppins
 * que vem no projeto (resources/fonts, licença OFL), pra sair igual em qualquer servidor. Gerado com GD.
 */
abstract class WhatsAppCardImage
{
    protected const SIZE = 1080;

    protected const DEFAULT_ACCENT = '#4f46e5';

    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagettftext') && is_file(self::font('ExtraBold'));
    }

    /**
     * Tela com o fundo na cor da loja (escurecendo pra baixo) e os círculos claros de decoração.
     *
     * @return array{0: \GdImage, 1: int, 2: int, 3: int}  imagem e a cor de destaque (r, g, b)
     */
    protected function canvas(Company $company): array
    {
        $img = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagesavealpha($img, true);
        imagealphablending($img, true);

        [$r, $g, $b] = $this->hexToRgb($company->catalog_accent_color ?: self::DEFAULT_ACCENT);

        for ($y = 0; $y < self::SIZE; $y++) {
            $f = 1 - 0.45 * ($y / self::SIZE);
            imageline($img, 0, $y, self::SIZE, $y, imagecolorallocate($img, (int) ($r * $f), (int) ($g * $f), (int) ($b * $f)));
        }

        $glow = imagecolorallocatealpha($img, 255, 255, 255, 112);
        imagefilledellipse($img, 960, 120, 520, 520, $glow);
        imagefilledellipse($img, 90, 1000, 460, 460, $glow);

        return [$img, $r, $g, $b];
    }

    /** Faixa branca arredondada com o texto centralizado (ex: "Use na sua próxima compra"). */
    protected function pill(\GdImage $img, string $text, int $y, int $textColor, int $size = 38): void
    {
        $box = imagettfbbox($size, 0, self::font('SemiBold'), $text);
        $w = min(self::SIZE - 80, abs($box[2] - $box[0]) + 110);
        $x = (int) ((self::SIZE - $w) / 2);
        $this->roundedRect($img, $x, $y, $x + $w, $y + 92, 46, imagecolorallocate($img, 255, 255, 255));
        $this->centered($img, $text, self::font('SemiBold'), $size, $y + 62, $textColor);
    }

    protected function toJpeg(\GdImage $img): ?string
    {
        ob_start();
        imagejpeg($img, null, 90);

        return ob_get_clean() ?: null;
    }

    protected function firstName(string $name): string
    {
        return mb_substr(trim(explode(' ', trim($name))[0] ?? ''), 0, 16);
    }

    protected static function font(string $weight): string
    {
        return resource_path("fonts/Poppins-{$weight}.ttf");
    }

    /** Desenha a logo (ou o nome da loja) no topo e devolve onde ela termina. */
    protected function logo(\GdImage $img, Company $company): int
    {
        $white = imagecolorallocate($img, 255, 255, 255);
        $logo = null;

        if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $logo = @imagecreatefromstring(Storage::disk('public')->get($company->logo_path)) ?: null;
        }

        if (! $logo) {
            $name = mb_substr($company->name, 0, 28);
            $this->centered($img, $name, self::font('Bold'), 40, 130, $white);

            return 140;
        }

        $maxW = 340;
        $maxH = 130;
        $scale = min($maxW / imagesx($logo), $maxH / imagesy($logo));
        $w = (int) (imagesx($logo) * $scale);
        $h = (int) (imagesy($logo) * $scale);
        $pad = 26;
        $x = (int) ((self::SIZE - $w) / 2);
        $y = 70;

        $this->roundedRect($img, $x - $pad, $y - $pad, $x + $w + $pad, $y + $h + $pad, 28, $white);
        imagecopyresampled($img, $logo, $x, $y, 0, 0, $w, $h, imagesx($logo), imagesy($logo));

        return $y + $h + $pad;
    }

    protected function coin(\GdImage $img, int $cx, int $cy, int $d): void
    {
        imagefilledellipse($img, $cx + 6, $cy + 8, $d, $d, imagecolorallocatealpha($img, 0, 0, 0, 95));
        imagefilledellipse($img, $cx, $cy, $d, $d, imagecolorallocate($img, 202, 138, 4));
        imagefilledellipse($img, $cx, $cy, (int) ($d * 0.84), (int) ($d * 0.84), imagecolorallocate($img, 250, 204, 21));
        $size = (int) ($d * 0.42);
        $box = imagettfbbox($size, 0, self::font('ExtraBold'), '$');
        imagettftext(
            $img, $size, 0,
            (int) ($cx - ($box[2] - $box[0]) / 2), (int) ($cy + ($box[1] - $box[7]) / 2),
            imagecolorallocate($img, 161, 98, 7), self::font('ExtraBold'), '$'
        );
    }

    protected function centered(\GdImage $img, string $text, string $font, int $size, int $baseline, int $color, int $dx = 0): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $width = abs($box[2] - $box[0]);
        imagettftext($img, $size, 0, (int) ((self::SIZE - $width) / 2) + $dx, $baseline, $color, $font, $text);
    }

    /** Maior tamanho de fonte (até $max) em que o texto cabe na largura. */
    protected function fitSize(string $text, string $font, int $max, int $width, int $min = 40): int
    {
        for ($size = $max; $size > $min; $size -= 2) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (abs($box[2] - $box[0]) <= $width) {
                return $size;
            }
        }

        return $min;
    }

    protected function roundedRect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $radius * 2, $radius * 2, $color);
        }
    }

    /** @return array{0:int,1:int,2:int} */
    protected function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = ltrim(self::DEFAULT_ACCENT, '#');
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
