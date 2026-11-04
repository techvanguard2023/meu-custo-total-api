<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conta de afiliado conectada por API (Shopee). O Secret é criptografado no banco e escondido das respostas. */
class AffiliateAccount extends Model
{
    public const PROVIDER_SHOPEE = 'shopee';

    protected $fillable = ['company_id', 'provider', 'app_id', 'secret', 'verified_at', 'last_error'];

    protected $casts = [
        'secret' => 'encrypted',
        'verified_at' => 'datetime',
    ];

    protected $hidden = ['secret'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function shopeeFor(int $companyId): ?self
    {
        return self::where('company_id', $companyId)->where('provider', self::PROVIDER_SHOPEE)->first();
    }
}
