<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Briefing da arte de um pedido: o cliente responde, por um link público, as perguntas que a loja definiu (cores,
 * texto, tema, nomes, referências...). Guarda a cópia das perguntas do momento do envio junto com as respostas.
 */
class ArtBrief extends Model
{
    public const PENDING = 'pending';

    public const SUBMITTED = 'submitted';

    protected $fillable = ['company_id', 'quote_id', 'token', 'status', 'questions', 'answers', 'viewed_at', 'submitted_at', 'seen_at'];

    /** Nas listas (produção, vendas) só vão status e datas: link, perguntas e respostas ficam no card do pedido. */
    protected $hidden = ['token', 'questions', 'answers'];

    protected $casts = [
        'questions' => 'array',
        'answers' => 'array',
        'viewed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'seen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (ArtBrief $brief) {
            $paths = $brief->files()->pluck('path')->all();
            if ($paths !== []) {
                Storage::disk('public')->delete($paths);
            }
        });
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ArtBriefFile::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Endereço que o cliente abre (no domínio próprio da loja, quando ela tem). */
    public function publicUrl(): string
    {
        return $this->company->publicOrigin().'/briefing/'.$this->token;
    }
}
