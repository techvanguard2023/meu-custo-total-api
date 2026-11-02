<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = ['offer_post_id', 'company_id', 'offer_series_id', 'offer_group_id', 'target_label', 'target_jid', 'status', 'error', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(OfferPost::class, 'offer_post_id');
    }
}
