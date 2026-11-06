<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Depoimento de uma loja sobre o Meu Custo Total (um por loja). Só vai pra landing depois que o admin aprova, e só
 * com a autorização dada na hora (consent_at). A loja pode apagar quando quiser (Indique e ganhe).
 */
class PlatformTestimonial extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_HIDDEN];

    /** Texto da autorização (o mesmo do frontend). */
    public const CONSENT_TEXT = 'Autorizo o Meu Custo Total a publicar este depoimento com meu primeiro nome, o nome e o logo da loja no site e nas redes sociais.';

    protected $fillable = ['company_id', 'user_id', 'text', 'author_name', 'status', 'consent_at', 'reviewed_at'];

    protected $casts = [
        'consent_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
