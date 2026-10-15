<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Versão "de compartilhamento" de uma imagem: 1200×630 (proporção 1,91:1 das prévias de link), JPEG leve.
 * O WhatsApp descarta a imagem da prévia quando ela é pesada demais (banners de 2–4 MB ficam sem foto),
 * então o link da coleção aponta pra esta cópia, e o catálogo continua usando o banner original.
 */
class ShareImageGenerator
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;
    /** Limite em que o WhatsApp mostra a imagem com folga. */
    private const MAX_BYTES = 280 * 1024;

    /** Caminho (no disco public) da cópia de compartilhamento de uma imagem. */
    public static function pathFor(string $sourcePath): string
    {
        return 'share/'.pathinfo($sourcePath, PATHINFO_FILENAME).'.jpg';
    }

    /** Gera a cópia a partir do arquivo original. false = não deu (sem GD, arquivo inválido...) e a prévia usa o original. */
    public function generate(string $sourcePath, string $disk = 'public'): bool
    {
        if (! extension_loaded('gd') || ! Storage::disk($disk)->exists($sourcePath)) {
            return false;
        }

        try {
            $source = @imagecreatefromstring(Storage::disk($disk)->get($sourcePath));
            if (! $source) {
                return false;
            }

            $sw = imagesx($source);
            $sh = imagesy($source);

            // "cover": preenche os 1200×630 e corta o excesso pelo centro
            $scale = max(self::WIDTH / $sw, self::HEIGHT / $sh);
            $cropW = (int) round(self::WIDTH / $scale);
            $cropH = (int) round(self::HEIGHT / $scale);
            $cropX = (int) floor(($sw - $cropW) / 2);
            $cropY = (int) floor(($sh - $cropH) / 2);

            $target = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
            // PNG/WebP com transparência: JPEG não tem, então fundo branco
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, $cropX, $cropY, self::WIDTH, self::HEIGHT, $cropW, $cropH);

            // Reduz a qualidade até ficar leve
            $bytes = '';
            foreach ([84, 76, 68, 60, 50] as $quality) {
                ob_start();
                imagejpeg($target, null, $quality);
                $bytes = (string) ob_get_clean();
                if (strlen($bytes) <= self::MAX_BYTES) {
                    break;
                }
            }

            Storage::disk($disk)->put(self::pathFor($sourcePath), $bytes);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Imagem de compartilhamento não gerada ({$sourcePath}): ".$e->getMessage());

            return false;
        }
    }

    public function delete(?string $sourcePath, string $disk = 'public'): void
    {
        if ($sourcePath) {
            Storage::disk($disk)->delete(self::pathFor($sourcePath));
        }
    }
}
