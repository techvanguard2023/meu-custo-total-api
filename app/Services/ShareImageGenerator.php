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

    /** Motivo da última falha de generate() — o comando mostra pra quem for diagnosticar. */
    public ?string $lastError = null;

    /**
     * Gera a cópia a partir do arquivo original. false = não deu (sem biblioteca de imagem, arquivo inválido...)
     * e a prévia segue usando o original; o motivo fica em $lastError.
     */
    public function generate(string $sourcePath, string $disk = 'public'): bool
    {
        $this->lastError = null;

        if (! Storage::disk($disk)->exists($sourcePath)) {
            $this->lastError = "arquivo não encontrado no disco '{$disk}'";

            return false;
        }

        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->lastError = 'o servidor não tem GD nem Imagick (extensões PHP de imagem)';

            return false;
        }

        try {
            $contents = Storage::disk($disk)->get($sourcePath);
            $bytes = extension_loaded('gd') ? $this->renderWithGd($contents) : $this->renderWithImagick($contents);

            Storage::disk($disk)->put(self::pathFor($sourcePath), $bytes);

            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::warning("Imagem de compartilhamento não gerada ({$sourcePath}): ".$e->getMessage());

            return false;
        }
    }

    private function renderWithGd(string $contents): string
    {
        $source = @imagecreatefromstring($contents);
        if (! $source) {
            throw new \RuntimeException('arquivo de imagem inválido ou formato não suportado pelo GD');
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

        return $bytes;
    }

    /** Mesma coisa pela extensão Imagick, para servidores sem GD. */
    private function renderWithImagick(string $contents): string
    {
        $image = new \Imagick();
        $image->readImageBlob($contents);
        // Transparência vira fundo branco (JPEG não tem)
        $image->setImageBackgroundColor('white');
        $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $image->cropThumbnailImage(self::WIDTH, self::HEIGHT);
        $image->setImageFormat('jpeg');

        $bytes = '';
        foreach ([84, 76, 68, 60, 50] as $quality) {
            $image->setImageCompressionQuality($quality);
            $bytes = $image->getImageBlob();
            if (strlen($bytes) <= self::MAX_BYTES) {
                break;
            }
        }
        $image->clear();

        return $bytes;
    }

    public function delete(?string $sourcePath, string $disk = 'public'): void
    {
        if ($sourcePath) {
            Storage::disk($disk)->delete(self::pathFor($sourcePath));
        }
    }
}
