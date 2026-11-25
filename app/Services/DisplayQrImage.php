<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Imagem do QR Code (PNG quadrado ou SVG, fundo branco) — a do expositor e as de divulgação. Alta resolução pra
 * imprimir sem serrilhar. Pode ter a cor da loja e a logo no centro: com logo, o QR usa a correção de erro mais
 * alta (até 30% do desenho pode faltar), e a logo cobre bem menos que isso.
 */
class DisplayQrImage
{
    private const SIZE = 1200;

    /** Margem branca em volta, em módulos — os leitores precisam dela pra achar o QR. */
    private const QUIET_ZONE = 4;

    /** Lado da área da logo, em fração do QR (sem a margem). */
    private const LOGO_FRACTION = 0.22;

    /** Cor escura o bastante para a câmera ler (hex #RRGGBB)? */
    public static function readableColor(?string $hex): bool
    {
        if (! $hex || ! preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
            return false;
        }
        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split(substr($hex, 1), 2));
        $lin = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        // Luminância relativa: contraste com o branco de pelo menos ~4:1
        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b) <= 0.21;
    }

    private function matrix(string $url, bool $withLogo)
    {
        return Encoder::encode($url, $withLogo ? ErrorCorrectionLevel::H() : ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
    }

    /** @param string|null $logo bytes da logo (PNG/JPEG/WebP) */
    public function render(string $url, ?string $color = null, ?string $logo = null): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $logoImage = $logo ? @imagecreatefromstring($logo) : null;
        $matrix = $this->matrix($url, (bool) $logoImage);
        $modules = $matrix->getWidth() + self::QUIET_ZONE * 2;
        $cell = intdiv(self::SIZE, $modules);
        $size = $cell * $modules;

        [$r, $g, $b] = self::readableColor($color) ? array_map('hexdec', str_split(substr($color, 1), 2)) : [0, 0, 0];

        $img = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($img, 255, 255, 255);
        $dark = imagecolorallocate($img, $r, $g, $b);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $white);

        for ($row = 0; $row < $matrix->getHeight(); $row++) {
            for ($col = 0; $col < $matrix->getWidth(); $col++) {
                if ($matrix->get($col, $row) === 1) {
                    $x = ($col + self::QUIET_ZONE) * $cell;
                    $y = ($row + self::QUIET_ZONE) * $cell;
                    imagefilledrectangle($img, $x, $y, $x + $cell - 1, $y + $cell - 1, $dark);
                }
            }
        }

        if ($logoImage) {
            // Quadro branco no centro e a logo dentro, sem distorcer
            $box = (int) round($matrix->getWidth() * $cell * self::LOGO_FRACTION);
            $x0 = intdiv($size - $box, 2);
            imagefilledrectangle($img, $x0, $x0, $x0 + $box - 1, $x0 + $box - 1, $white);
            $pad = (int) round($box * 0.08);
            $inner = $box - 2 * $pad;
            $lw = imagesx($logoImage);
            $lh = imagesy($logoImage);
            $scale = min($inner / $lw, $inner / $lh);
            $w = (int) round($lw * $scale);
            $h = (int) round($lh * $scale);
            imagealphablending($img, true);
            imagecopyresampled($img, $logoImage, $x0 + $pad + intdiv($inner - $w, 2), $x0 + $pad + intdiv($inner - $h, 2), 0, 0, $w, $h, $lw, $lh);
        }

        ob_start();
        imagepng($img, null, 9);

        return ob_get_clean() ?: null;
    }

    /**
     * Vetor (SVG): para gráfica, panfleto e banner impresso — não serrilha em nenhum tamanho.
     *
     * @param string|null $logoDataUri logo já como data: URI (fica embutida no arquivo)
     */
    public function svg(string $url, ?string $color = null, ?string $logoDataUri = null): string
    {
        $matrix = $this->matrix($url, (bool) $logoDataUri);
        $width = $matrix->getWidth();
        $size = $width + self::QUIET_ZONE * 2;
        $fill = self::readableColor($color) ? $color : '#000000';

        // Um caminho só com um quadrado por módulo escuro (arquivo leve e nítido)
        $path = '';
        for ($row = 0; $row < $matrix->getHeight(); $row++) {
            for ($col = 0; $col < $width; $col++) {
                if ($matrix->get($col, $row) === 1) {
                    $path .= 'M'.($col + self::QUIET_ZONE).' '.($row + self::QUIET_ZONE).'h1v1h-1z';
                }
            }
        }

        $logo = '';
        if ($logoDataUri) {
            $box = round($width * self::LOGO_FRACTION, 2);
            $x0 = round(($size - $box) / 2, 2);
            $pad = round($box * 0.08, 2);
            $inner = round($box - 2 * $pad, 2);
            $logo = "<rect x=\"{$x0}\" y=\"{$x0}\" width=\"{$box}\" height=\"{$box}\" fill=\"#ffffff\"/>"
                .'<image href="'.htmlspecialchars($logoDataUri, ENT_QUOTES).'" x="'.($x0 + $pad).'" y="'.($x0 + $pad)."\" width=\"{$inner}\" height=\"{$inner}\" preserveAspectRatio=\"xMidYMid meet\"/>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            ."<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$size} {$size}\" width=\"1000\" height=\"1000\" shape-rendering=\"crispEdges\">"
            ."<rect width=\"{$size}\" height=\"{$size}\" fill=\"#ffffff\"/><path d=\"{$path}\" fill=\"{$fill}\"/>{$logo}</svg>";
    }
}
