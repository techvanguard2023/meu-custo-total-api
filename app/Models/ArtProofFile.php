<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Um arquivo da prova de arte (imagem, PDF ou modelo 3D). O nome no disco é aleatório: o link não dá pra adivinhar. */
class ArtProofFile extends Model
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'stl', '3mf', 'obj'];

    protected $fillable = ['art_proof_id', 'path', 'original_name', 'mime', 'size', 'position'];

    protected $casts = ['size' => 'integer', 'position' => 'integer'];

    protected $appends = ['url', 'is_image'];

    public function proof(): BelongsTo
    {
        return $this->belongsTo(ArtProof::class, 'art_proof_id');
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
