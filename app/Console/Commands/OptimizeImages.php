<?php

namespace App\Console\Commands;

use App\Models\AffiliateOffer;
use App\Models\CatalogBanner;
use App\Models\Company;
use App\Models\DisplayVisit;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductImage;
use App\Services\ImageOptimizer;
use App\Services\ShareImageGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Otimiza as imagens que já estavam guardadas antes do ImageOptimizer (fotos de produto, logos, banners,
 * coleções, fotos de visita, achadinhos). Sem --apply só mostra o que faria e quanto economiza. Com --apply:
 * grava o arquivo novo, atualiza o banco e só então apaga o antigo. Pode rodar de novo: pula o que já está bom.
 */
class OptimizeImages extends Command
{
    protected $signature = 'images:optimize {--apply : grava de verdade (sem isso, só simula)} {--company= : só uma loja}';

    protected $description = 'Converte e reduz as imagens já guardadas (WebP/JPEG no tamanho certo)';

    private int $before = 0;

    private int $after = 0;

    private int $changed = 0;

    private int $skipped = 0;

    private int $failed = 0;

    public function handle(ImageOptimizer $optimizer, ShareImageGenerator $share): int
    {
        $apply = (bool) $this->option('apply');
        $company = $this->option('company') ? (int) $this->option('company') : null;
        $this->info($apply ? 'Otimizando as imagens…' : 'Simulação (nada é alterado; use --apply pra gravar)…');

        // Fotos de produto: WebP + cópia JPEG pra prévia de link e WhatsApp
        ProductImage::query()
            ->when($company, fn ($q) => $q->whereIn('product_id', Product::where('company_id', $company)->select('id')))
            ->whereNull('share_path')
            ->each(function (ProductImage $image) use ($optimizer, $apply) {
                $this->convert($image->path, fn (string $bytes) => $optimizer->optimize($bytes, 'product'), $apply, function (string $newPath, string $bytes) use ($image, $optimizer) {
                    $share = $optimizer->optimize($bytes, 'product_share');
                    $sharePath = null;
                    if ($share) {
                        $sharePath = preg_replace('/\.\w+$/', '', $newPath).'.share.'.$share['ext'];
                        Storage::disk('public')->put($sharePath, $share['bytes']);
                    }
                    $image->update(['path' => $newPath, 'share_path' => $sharePath]);
                }, 'product', force: true);
            });

        // Foto antiga do produto (campo único, de antes da galeria): JPEG, porque também vai na prévia de link
        Product::whereNotNull('image_path')->when($company, fn ($q) => $q->where('company_id', $company))
            ->each(fn (Product $p) => $this->convert($p->image_path, fn ($b) => $optimizer->optimize($b, 'product_share'), $apply, fn ($new) => $p->forceFill(['image_path' => $new])->save(), 'product_share'));

        Company::whereNotNull('logo_path')->when($company, fn ($q) => $q->whereKey($company))
            ->each(fn (Company $c) => $this->convert($c->logo_path, fn ($b) => $optimizer->optimize($b, 'logo'), $apply, fn ($new) => $c->forceFill(['logo_path' => $new])->save(), 'logo'));

        CatalogBanner::query()->when($company, fn ($q) => $q->where('company_id', $company))
            ->each(fn (CatalogBanner $b) => $this->convert($b->image_path, fn ($x) => $optimizer->optimize($x, 'banner'), $apply, fn ($new) => $b->update(['image_path' => $new]), 'banner'));

        ProductCollection::whereNotNull('banner_path')->when($company, fn ($q) => $q->where('company_id', $company))
            ->each(fn (ProductCollection $c) => $this->convert($c->banner_path, fn ($x) => $optimizer->optimize($x, 'collection'), $apply, function ($new) use ($c, $share) {
                $old = $c->banner_path;
                $c->update(['banner_path' => $new]);
                // A cópia da prévia do link tem o nome derivado do banner: refaz pro novo e apaga a antiga
                $share->delete($old);
                $share->generate($new);
            }, 'collection'));

        DisplayVisit::whereNotNull('photo_path')
            ->when($company, fn ($q) => $q->whereHas('display', fn ($d) => $d->where('company_id', $company)))
            ->each(fn (DisplayVisit $v) => $this->convert($v->photo_path, fn ($x) => $optimizer->optimize($x, 'visit'), $apply, fn ($new) => $v->forceFill(['photo_path' => $new])->save(), 'visit'));

        AffiliateOffer::whereNotNull('image_path')->when($company, fn ($q) => $q->where('company_id', $company))
            ->each(fn (AffiliateOffer $o) => $this->convert($o->image_path, fn ($x) => $optimizer->optimize($x, 'affiliate'), $apply, fn ($new) => $o->forceFill(['image_path' => $new])->save(), 'affiliate'));

        $this->newLine();
        $this->table(['', ''], [
            [$apply ? 'Convertidas' : 'Seriam convertidas', $this->changed],
            ['Já estavam boas', $this->skipped],
            ['Não deu pra abrir (ficaram como estão)', $this->failed],
            ['Tamanho antes → depois', $this->kb($this->before).' → '.$this->kb($this->after)],
        ]);

        return self::SUCCESS;
    }

    /**
     * Converte um arquivo: pula se já está no formato e tamanho certos; senão grava o novo, chama $save (que
     * atualiza o banco) e apaga o antigo. $force = converte mesmo se já parecer bom (foto de produto sem cópia).
     */
    private function convert(?string $path, callable $optimize, bool $apply, callable $save, string $preset, bool $force = false): void
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return;
        }

        $bytes = Storage::disk('public')->get($path);
        [$max, $format] = ImageOptimizer::PRESETS[$preset];
        $info = @getimagesizefromstring($bytes);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $wanted = ['webp' => ['webp'], 'jpeg' => ['jpg', 'jpeg'], 'auto' => ['jpg', 'jpeg', 'png']][$format];
        if (! $force && $info && max($info[0], $info[1]) <= $max && in_array($ext, $wanted, true) && strlen($bytes) <= 400 * 1024) {
            $this->skipped++;

            return;
        }

        $result = $optimize($bytes);
        if (! $result) {
            $this->failed++;

            return;
        }
        // Já estava menor do que ficaria (e no formato certo): não mexe
        if (! $force && strlen($result['bytes']) >= strlen($bytes) && in_array($ext, $wanted, true)) {
            $this->skipped++;

            return;
        }

        $this->changed++;
        $this->before += strlen($bytes);
        $this->after += strlen($result['bytes']);
        if (! $apply) {
            return;
        }

        try {
            $newPath = dirname($path).'/'.Str::random(40).'.'.$result['ext'];
            Storage::disk('public')->put($newPath, $result['bytes']);
            $save($newPath, $bytes);
            Storage::disk('public')->delete($path);
        } catch (Throwable $e) {
            $this->failed++;
            $this->warn("Falhou em {$path}: ".$e->getMessage());
        }
    }

    private function kb(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }
}
