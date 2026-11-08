<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pix gerado pelo sistema para uma venda (pedido no Mercado Pago) — ver App\Services\PixCharges. */
class PaymentCharge extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'company_id', 'quote_id', 'provider', 'method', 'origin', 'allocations', 'external_id', 'external_payment_id', 'amount', 'status',
        'provider_status', 'qr_code', 'ticket_url', 'expires_at', 'paid_at', 'synced_at', 'user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'allocations' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public const METHOD_PIX = 'pix';
    /** Link do Checkout Pro (página do Mercado Pago: cartão parcelado ou Pix) */
    public const METHOD_LINK = 'link';

    /** Partes que este pagamento quita: [quote_id => valor] (sem allocations = só a venda principal, valor todo). */
    public function parts(): array
    {
        return $this->allocations
            ? collect($this->allocations)->mapWithKeys(fn ($a) => [(int) $a['quote_id'] => (float) $a['amount']])->all()
            : [$this->quote_id => (float) $this->amount];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
