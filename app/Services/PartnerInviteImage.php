<?php

namespace App\Services;

use App\Models\Company;

/**
 * Cartão do convite da área do parceiro (vai com o link pelo WhatsApp): "Olá, Carla! Sua área de
 * parceiro está pronta!", com o nome do expositor. Se o parceiro já tem comissão, ela aparece em
 * destaque; senão, o que ele consegue fazer na área.
 */
class PartnerInviteImage extends WhatsAppCardImage
{
    public function render(Company $company, string $contactName, string $displayName, float $commissionTotal): ?string
    {
        if (! self::isAvailable()) {
            return null;
        }

        [$img, $r, $g, $b] = $this->canvas($company);

        $this->coin($img, 935, 225, 100);
        $this->coin($img, 860, 135, 60);

        $white = imagecolorallocate($img, 255, 255, 255);
        $soft = imagecolorallocatealpha($img, 255, 255, 255, 25);
        $yellow = imagecolorallocate($img, 253, 224, 71);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 90);
        $accent = imagecolorallocate($img, $r, $g, $b);

        $logoBottom = $this->logo($img, $company);
        $top = max($logoBottom + 60, 250);

        $first = $this->firstName($contactName);
        $this->centered($img, $first !== '' ? "Olá, {$first}!" : 'Olá!', self::font('Medium'), 50, $top + 50, $soft);
        $this->centered($img, 'Sua área de parceiro', self::font('Bold'), 66, $top + 145, $white);
        $this->centered($img, 'está pronta!', self::font('Bold'), 66, $top + 228, $yellow);

        if ($commissionTotal >= 0.01) {
            // Já vendeu pelo expositor: o valor ganho chama mais atenção que a lista
            $value = 'R$ '.number_format($commissionTotal, 2, ',', '.');
            $size = $this->fitSize($value, self::font('ExtraBold'), 108, 860);
            $this->centered($img, 'Você já ganhou', self::font('Medium'), 38, $top + 300, $soft);
            $this->centered($img, $value, self::font('ExtraBold'), $size, $top + 445 + 5, $shadow, 5);
            $this->centered($img, $value, self::font('ExtraBold'), $size, $top + 445, $yellow);
            $this->centered($img, 'de comissão', self::font('Bold'), 50, $top + 520, $white);
        } else {
            $this->bullets($img, [
                'Veja quanto você já ganhou',
                'Lance suas vendas na hora',
                'Confira o que tem no expositor',
            ], $top + 320, $white);
        }

        $name = mb_strlen($displayName) > 32 ? rtrim(mb_substr($displayName, 0, 31)).'…' : $displayName;
        $label = 'Expositor: '.$name;
        $this->pill($img, $label, $top + 560, $accent, $this->fitSize($label, self::font('SemiBold'), 38, 860, 24));
        $this->centered($img, 'Entre com este WhatsApp · o código chega aqui', self::font('Medium'), 30, min(self::SIZE - 40, $top + 730), $soft);

        return $this->toJpeg($img);
    }

    /** Lista com bolinhas amarelas, alinhada à esquerda e centralizada como bloco. */
    private function bullets(\GdImage $img, array $items, int $firstBaseline, int $color): void
    {
        $font = self::font('SemiBold');
        $size = 40;
        $widest = 0;
        foreach ($items as $item) {
            $box = imagettfbbox($size, 0, $font, $item);
            $widest = max($widest, abs($box[2] - $box[0]));
        }

        $dot = 26;
        $gap = 28;
        $x = (int) ((self::SIZE - ($dot + $gap + $widest)) / 2);
        $yellow = imagecolorallocate($img, 253, 224, 71);

        foreach (array_values($items) as $i => $item) {
            $baseline = $firstBaseline + $i * 74;
            imagefilledellipse($img, $x + (int) ($dot / 2), $baseline - 15, $dot, $dot, $yellow);
            imagettftext($img, $size, 0, $x + $dot + $gap, $baseline, $color, $font, $item);
        }
    }
}
