<?php

namespace App\Console\Commands;

use App\Models\ProductCollection;
use App\Services\ShareImageGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('collections:generate-share-images {--force : Gera de novo mesmo as que já existem}')]
#[Description('Gera a imagem leve (1200x630) dos banners de coleção usada na prévia do WhatsApp')]
class GenerateShareImages extends Command
{
    public function handle(ShareImageGenerator $generator): int
    {
        $done = 0;
        $failed = 0;

        $this->line('Bibliotecas de imagem: GD '.(extension_loaded('gd') ? 'sim' : 'NÃO').' | Imagick '.(extension_loaded('imagick') ? 'sim' : 'NÃO'));

        foreach (ProductCollection::whereNotNull('banner_path')->get() as $collection) {
            if (! $this->option('force') && Storage::disk('public')->exists(ShareImageGenerator::pathFor($collection->banner_path))) {
                continue;
            }

            if ($generator->generate($collection->banner_path)) {
                $done++;
                $this->line("OK   #{$collection->id} {$collection->name}");
            } else {
                $failed++;
                $this->warn("Falhou #{$collection->id} {$collection->name} ({$collection->banner_path}): {$generator->lastError}");
            }
        }

        $this->info("Concluído: {$done} gerada(s), {$failed} falha(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
