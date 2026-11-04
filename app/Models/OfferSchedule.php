<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Agenda automática das ofertas da loja (ver OfferScheduler). */
class OfferSchedule extends Model
{
    /** Padrão combinado: seg a sáb, 10h e 19h, 3 produtos por envio. */
    public const DEFAULTS = [
        'enabled' => false,
        'days' => [1, 2, 3, 4, 5, 6],
        'times' => ['10:00', '19:00'],
        'products_per_run' => 3,
        'only_in_stock' => true,
    ];

    protected $fillable = ['company_id', 'purpose', 'enabled', 'days', 'times', 'products_per_run', 'only_in_stock', 'last_slot'];

    protected $casts = [
        'enabled' => 'boolean',
        'days' => 'array',
        'times' => 'array',
        'products_per_run' => 'integer',
        'only_in_stock' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
