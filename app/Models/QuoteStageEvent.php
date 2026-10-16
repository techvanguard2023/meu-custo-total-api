<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quando um pedido entrou numa etapa da produção (ver Quote::booted). */
class QuoteStageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['quote_id', 'stage'];

    protected $casts = ['created_at' => 'datetime'];
}
