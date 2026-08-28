<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Uma visita ao expositor: reposição ou conferência. É onde a foto de
 * evidência fica — uma por visita (o expositor inteiro), não uma por produto.
 */
class DisplayVisit extends Model
{
    public const TYPE_RESTOCK = 'restock';
    public const TYPE_RECONCILIATION = 'reconciliation';

    protected $fillable = ['display_id', 'type', 'photo_path', 'quote_id'];

    protected $appends = ['photo_url'];

    protected function photoUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null
        );
    }

    public function display(): BelongsTo
    {
        return $this->belongsTo(Display::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(DisplayStockMovement::class);
    }
}
