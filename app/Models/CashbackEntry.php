<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma linha do extrato de cashback do cliente (crédito ou saída). */
class CashbackEntry extends Model
{
    public const TYPE_PURCHASE = 'purchase';       // compra entregue e paga
    public const TYPE_REVIEW = 'review';           // avaliou a compra
    public const TYPE_REFERRAL = 'referral';       // quem indicou: o indicado fez a 1ª compra
    public const TYPE_REFERRAL_WELCOME = 'referral_welcome'; // o indicado: bônus na 1ª compra
    public const TYPE_REDEEM = 'redeem';           // usado num pedido
    public const TYPE_REDEEM_REFUND = 'redeem_refund'; // pedido em que foi usado foi cancelado
    public const TYPE_REVERSAL = 'reversal';       // compra que gerou o crédito foi cancelada
    public const TYPE_EXPIRE = 'expire';           // venceu sem ser usado
    public const TYPE_ADJUST = 'adjust';           // ajuste manual da loja

    protected $fillable = [
        'company_id', 'customer_id', 'type', 'amount', 'remaining', 'expires_at', 'quote_id', 'user_id', 'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'remaining' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
