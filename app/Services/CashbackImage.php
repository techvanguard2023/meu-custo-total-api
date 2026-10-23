<?php

namespace App\Services;

use App\Models\Company;

/** Cartão do aviso de cashback: "Oi, Maria! Você ganhou R$ X de cashback", com a validade. */
class CashbackImage extends WhatsAppCardImage
{
    /** JPEG pronto pra enviar; null quando o servidor não consegue gerar (aí vai só o texto). */
    public function render(Company $company, string $customerName, float $amount, \DateTimeInterface $validUntil): ?string
    {
        if (! self::isAvailable()) {
            return null;
        }

        [$img, $r, $g, $b] = $this->canvas($company);

        $this->coin($img, 905, 255, 120);
        $this->coin($img, 985, 380, 80);
        $this->coin($img, 105, 760, 85);

        $white = imagecolorallocate($img, 255, 255, 255);
        $soft = imagecolorallocatealpha($img, 255, 255, 255, 25);
        $yellow = imagecolorallocate($img, 253, 224, 71);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 90);
        $accent = imagecolorallocate($img, $r, $g, $b);

        $logoBottom = $this->logo($img, $company);

        $first = $this->firstName($customerName);
        $top = max($logoBottom + 70, 250);
        $this->centered($img, $first !== '' ? "Oi, {$first}!" : 'Oi!', self::font('Medium'), 50, $top + 50, $soft);
        $this->centered($img, 'Você ganhou', self::font('Bold'), 74, $top + 160, $white);

        $value = 'R$ '.number_format($amount, 2, ',', '.');
        $size = $this->fitSize($value, self::font('ExtraBold'), 190, 900);
        $this->centered($img, $value, self::font('ExtraBold'), $size, $top + 365 + 6, $shadow, 6);
        $this->centered($img, $value, self::font('ExtraBold'), $size, $top + 365, $yellow);
        $this->centered($img, 'de cashback', self::font('Bold'), 76, $top + 470, $white);

        $y = $top + 535;
        $this->pill($img, 'Use na sua próxima compra', $y, $accent);
        $this->centered($img, 'Válido até '.$validUntil->format('d/m/Y'), self::font('Medium'), 32, min(self::SIZE - 50, $y + 170), $soft);

        return $this->toJpeg($img);
    }
}
