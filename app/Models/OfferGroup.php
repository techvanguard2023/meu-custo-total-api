<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferGroup extends Model
{
    protected $fillable = ['offer_series_id', 'number', 'jid', 'invite_url', 'participants_count', 'participants_checked_at', 'full_at', 'backup_admin_added'];

    protected $casts = [
        'participants_checked_at' => 'datetime',
        'full_at' => 'datetime',
        'backup_admin_added' => 'boolean',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(OfferSeries::class, 'offer_series_id');
    }
}
