<?php

namespace App\Services;

use App\Models\Display;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Placa do QR Code pra imprimir e colar no expositor (1200x1600, PNG): logo e cor da loja, chamada
 * de acordo com o destino, o QR grande num quadro branco e o código do expositor.
 */
class DisplayQrPlaque extends WhatsAppCardImage
{
    protected int $width = 1200;

    protected int $height = 1600;

    public function render(Display $display, string $url): ?string
    {
        if (! self::isAvailable()) {
            return null;
        }

        $company = $display->company;
        [$img, $r, $g, $b] = $this->canvas($company);

        $white = imagecolorallocate($img, 255, 255, 255);
        $soft = imagecolorallocatealpha($img, 255, 255, 255, 25);
        $yellow = imagecolorallocate($img, 253, 224, 71);

        $logoBottom = $this->logo($img, $company);

        [$headline, $sub] = match (true) {
            $display->qr_page_enabled => ['Peça o seu personalizado', 'WhatsApp e catálogo no seu celular'],
            $display->qr_destination === 'catalog' => ['Veja todos os modelos', 'O catálogo completo no seu celular'],
            default => ['Peça o seu personalizado', 'Fale com a gente pelo WhatsApp'],
        };

        $top = $logoBottom + 110;
        $this->centered($img, $headline, self::font('ExtraBold'), $this->fitSize($headline, self::font('ExtraBold'), 66, 1080), $top, $yellow);
        $this->centered($img, $sub, self::font('Medium'), 40, $top + 70, $soft);

        // QR num quadro branco arredondado
        $box = 820;
        $x = (int) (($this->width - $box) / 2);
        $y = $top + 120;
        $this->roundedRect($img, $x, $y, $x + $box, $y + $box, 48, $white);
        $this->drawQr($img, $url, $x + 50, $y + 50, $box - 100);

        $this->centered($img, 'Aponte a câmera do celular', self::font('Bold'), 52, $y + $box + 100, $white);
        $this->centered($img, 'Expositor '.$display->code, self::font('Medium'), 34, min($this->height - 50, $y + $box + 170), $soft);

        ob_start();
        imagepng($img, null, 6);

        return ob_get_clean() ?: null;
    }

    private function drawQr(\GdImage $img, string $url, int $x, int $y, int $size): void
    {
        $matrix = Encoder::encode($url, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $modules = $matrix->getWidth();
        $cell = intdiv($size, $modules);
        $offset = intdiv($size - $cell * $modules, 2);
        $dark = imagecolorallocate($img, 17, 24, 39);

        for ($row = 0; $row < $modules; $row++) {
            for ($col = 0; $col < $modules; $col++) {
                if ($matrix->get($col, $row) === 1) {
                    $px = $x + $offset + $col * $cell;
                    $py = $y + $offset + $row * $cell;
                    imagefilledrectangle($img, $px, $py, $px + $cell - 1, $py + $cell - 1, $dark);
                }
            }
        }
    }
}
