<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conta do Mercado Pago conectada pela loja (OAuth). Os tokens nunca saem do servidor. */
class PaymentAccount extends Model
{
    protected $fillable = [
        'company_id', 'provider', 'provider_user_id', 'access_token', 'refresh_token', 'public_key',
        'live_mode', 'expires_at', 'connected_at', 'revoked_at', 'last_error',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'live_mode' => 'boolean',
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
