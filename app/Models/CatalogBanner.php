<?php

namespace App\Models;

use App\Models\Concerns\BustsCatalogCache;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CatalogBanner extends Model
{
    use BustsCatalogCache;

    /** Catálogo público que mostra este registro (cache limpo ao gravar). */
    public function catalogCompanyId(): ?int
    {
        return $this->company_id;
    }

    protected $fillable = ['company_id', 'image_path', 'link_url', 'position'];

    protected $casts = [
        'position' => 'integer',
    ];

    protected $appends = ['image_url'];

    protected function imageUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->image_path ? Storage::disk('public')->url($this->image_path) : null
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
