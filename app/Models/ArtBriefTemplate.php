<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modelo de briefing da loja: um conjunto de perguntas com nome, ligado aos produtos que usam essas perguntas. */
class ArtBriefTemplate extends Model
{
    protected $fillable = ['company_id', 'name', 'questions'];

    protected $casts = ['questions' => 'array'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
