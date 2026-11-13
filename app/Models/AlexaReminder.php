<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lembrete diário da Alexa com o resumo das entregas (ver AlexaController e alexa:daily-summaries). */
class AlexaReminder extends Model
{
    protected $fillable = ['user_id', 'alexa_user_id', 'api_endpoint', 'alert_token', 'remind_at', 'last_synced_at'];

    protected $casts = ['last_synced_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
