<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um pagamento (ou correção) de uma venda — ver App\Services\QuotePayments. */
class QuotePayment extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_MERCADOPAGO = 'mercadopago';
    public const SOURCE_DISPLAY = 'display';
    public const SOURCE_IMPORT = 'import';

    protected $fillable = ['company_id', 'quote_id', 'amount', 'method', 'source', 'external_id', 'user_id', 'note', 'paid_at'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
