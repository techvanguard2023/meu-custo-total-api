<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Só o QR Code do expositor (PNG quadrado, fundo branco), pra o lojista colocar na placa do jeito
 * que quiser. Alta resolução pra imprimir sem serrilhar.
 */
class DisplayQrImage
{
    private const SIZE = 1200;

    /** Margem branca em volta, em módulos — os leitores precisam dela pra achar o QR. */
    private const QUIET_ZONE = 4;

    public function render(string $url): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $matrix = Encoder::encode($url, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $modules = $matrix->getWidth() + self::QUIET_ZONE * 2;
        $cell = intdiv(self::SIZE, $modules);
        $size = $cell * $modules;

        $img = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $white);

        for ($row = 0; $row < $matrix->getHeight(); $row++) {
            for ($col = 0; $col < $matrix->getWidth(); $col++) {
                if ($matrix->get($col, $row) === 1) {
                    $x = ($col + self::QUIET_ZONE) * $cell;
                    $y = ($row + self::QUIET_ZONE) * $cell;
                    imagefilledrectangle($img, $x, $y, $x + $cell - 1, $y + $cell - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($img, null, 9);

        return ob_get_clean() ?: null;
    }
}
