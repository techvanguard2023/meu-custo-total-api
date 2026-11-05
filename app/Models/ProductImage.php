<?php

namespace App\Models;

use App\Models\Concerns\BustsCatalogCache;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use BustsCatalogCache;

    /** Catálogo público que mostra este registro (cache limpo ao gravar). */
    public function catalogCompanyId(): ?int
    {
        return Product::whereKey($this->product_id)->value('company_id');
    }

    protected $fillable = ['product_id', 'path', 'share_path', 'position'];

    protected $casts = [
        'position' => 'integer',
    ];

    protected $appends = ['image_url'];

    protected $hidden = ['share_path'];

    protected function imageUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->path ? Storage::disk('public')->url($this->path) : null
        );
    }

    /** Cópia JPEG pra fora do app (prévia de link, WhatsApp); fotos antigas não têm — usa a própria. */
    public function shareUrl(): ?string
    {
        $path = $this->share_path ?: $this->path;

        return $path ? Storage::disk('public')->url($path) : null;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
