<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

/**
 * Imagem (1080x1080) que vai junto do aviso de cashback no WhatsApp: "Você ganhou R$ X de cashback",
 * com o nome do cliente, a cor e a logo da loja. Gerada com GD e uma fonte que vem no projeto
 * (resources/fonts, Poppins — licença OFL), pra sair igual em qualquer servidor.
 */
class CashbackImage
{
    private const SIZE = 1080;

    private const DEFAULT_ACCENT = '#4f46e5';

    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagettftext') && is_file(self::font('ExtraBold'));
    }

    /** JPEG pronto pra enviar; null quando o servidor não consegue gerar (aí vai só o texto). */
    public function render(Company $company, string $customerName, float $amount, \DateTimeInterface $validUntil): ?string
    {
        if (! self::isAvailable()) {
            return null;
        }

        $img = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagesavealpha($img, true);
        imagealphablending($img, true);

        [$r, $g, $b] = $this->hexToRgb($company->catalog_accent_color ?: self::DEFAULT_ACCENT);

        // Fundo: a cor da loja escurecendo pra baixo
        for ($y = 0; $y < self::SIZE; $y++) {
            $t = $y / self::SIZE;
            $f = 1 - 0.45 * $t;
            imageline($img, 0, $y, self::SIZE, $y, imagecolorallocate($img, (int) ($r * $f), (int) ($g * $f), (int) ($b * $f)));
        }

        // Círculos claros de fundo (decoração)
        $glow = imagecolorallocatealpha($img, 255, 255, 255, 112);
        imagefilledellipse($img, 960, 120, 520, 520, $glow);
        imagefilledellipse($img, 90, 1000, 460, 460, $glow);

        // Moedas
        $this->coin($img, 905, 255, 120);
        $this->coin($img, 985, 380, 80);
        $this->coin($img, 105, 760, 85);

        $white = imagecolorallocate($img, 255, 255, 255);
        $soft = imagecolorallocatealpha($img, 255, 255, 255, 25);
        $yellow = imagecolorallocate($img, 253, 224, 71);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 90);
        $accent = imagecolorallocate($img, $r, $g, $b);

        // Logo da loja num quadro branco arredondado (sem logo: o nome da loja)
        $logoBottom = $this->logo($img, $company);

        $first = mb_substr(trim(explode(' ', trim($customerName))[0] ?? ''), 0, 16);
        $top = max($logoBottom + 70, 250);
        $this->centered($img, $first !== '' ? "Oi, {$first}!" : 'Oi!', self::font('Medium'), 50, $top + 50, $soft);
        $this->centered($img, 'Você ganhou', self::font('Bold'), 74, $top + 160, $white);

        $value = 'R$ '.number_format($amount, 2, ',', '.');
        $size = $this->fitSize($value, self::font('ExtraBold'), 190, 900);
        $this->centered($img, $value, self::font('ExtraBold'), $size, $top + 365 + 6, $shadow, 6);
        $this->centered($img, $value, self::font('ExtraBold'), $size, $top + 365, $yellow);
        $this->centered($img, 'de cashback', self::font('Bold'), 76, $top + 470, $white);

        // Faixa branca com o convite
        $cta = 'Use na sua próxima compra';
        $box = imagettfbbox(38, 0, self::font('SemiBold'), $cta);
        $w = abs($box[2] - $box[0]) + 110;
        $x = (int) ((self::SIZE - $w) / 2);
        $y = $top + 535;
        $this->roundedRect($img, $x, $y, $x + $w, $y + 92, 46, $white);
        $this->centered($img, $cta, self::font('SemiBold'), 38, $y + 62, $accent);

        $this->centered($img, 'Válido até '.$validUntil->format('d/m/Y'), self::font('Medium'), 32, min(self::SIZE - 50, $y + 170), $soft);

        ob_start();
        imagejpeg($img, null, 90);

        return ob_get_clean() ?: null;
    }

    private static function font(string $weight): string
    {
        return resource_path("fonts/Poppins-{$weight}.ttf");
    }

    /** Desenha a logo (ou o nome da loja) no topo e devolve onde ela termina. */
    private function logo(\GdImage $img, Company $company): int
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

    private function coin(\GdImage $img, int $cx, int $cy, int $d): void
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

    private function centered(\GdImage $img, string $text, string $font, int $size, int $baseline, int $color, int $dx = 0): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $width = abs($box[2] - $box[0]);
        imagettftext($img, $size, 0, (int) ((self::SIZE - $width) / 2) + $dx, $baseline, $color, $font, $text);
    }

    /** Maior tamanho de fonte (até $max) em que o texto cabe na largura. */
    private function fitSize(string $text, string $font, int $max, int $width): int
    {
        for ($size = $max; $size > 40; $size -= 6) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (abs($box[2] - $box[0]) <= $width) {
                return $size;
            }
        }

        return 40;
    }

    private function roundedRect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $radius * 2, $radius * 2, $color);
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private function hexToRgb(string $hex): array
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
