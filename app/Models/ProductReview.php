<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    public const COMMENT_PENDING = 'pending';
    public const COMMENT_APPROVED = 'approved';
    public const COMMENT_REJECTED = 'rejected';

    protected $fillable = [
        'company_id', 'quote_id', 'product_id', 'rating', 'comment', 'comment_status', 'reviewer_name',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    protected $appends = ['reviewer_first_name'];

    /**
     * Só o primeiro nome vai pro catálogo público — o cliente avaliou um produto,
     * não autorizou o nome completo dele numa vitrine aberta na internet.
     */
    public function getReviewerFirstNameAttribute(): ?string
    {
        $name = trim((string) $this->reviewer_name);

        return $name !== '' ? explode(' ', $name)[0] : null;
    }

    /** Comentários liberados pelo lojista — os únicos que aparecem no catálogo. */
    public function scopeWithApprovedComment(Builder $query): Builder
    {
        return $query->whereNotNull('comment')->where('comment_status', self::COMMENT_APPROVED);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
