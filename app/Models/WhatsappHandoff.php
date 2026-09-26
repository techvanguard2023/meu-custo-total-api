<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappHandoff extends Model
{
    protected $fillable = ['company_id', 'phone', 'customer_name', 'reason', 'paused_until'];

    protected $casts = ['paused_until' => 'datetime'];

    public function isPaused(): bool
    {
        return $this->paused_until->isFuture();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
