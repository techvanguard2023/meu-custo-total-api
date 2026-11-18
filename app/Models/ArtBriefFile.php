<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Arquivo de referência que o cliente anexou no briefing (imagem, PDF ou modelo 3D). Nome no disco aleatório. */
class ArtBriefFile extends Model
{
    protected $fillable = ['art_brief_id', 'question_key', 'path', 'original_name', 'mime', 'size'];

    protected $casts = ['size' => 'integer'];

    protected $appends = ['url', 'is_image'];

    protected $hidden = ['path', 'art_brief_id', 'created_at', 'updated_at'];

    public function brief(): BelongsTo
    {
        return $this->belongsTo(ArtBrief::class, 'art_brief_id');
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->url($this->path));
    }

    protected function isImage(): Attribute
    {
        return Attribute::get(fn () => in_array(strtolower(pathinfo($this->path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true));
    }
}
