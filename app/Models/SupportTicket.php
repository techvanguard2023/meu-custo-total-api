<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Chamado de suporte aberto por alguém da loja ("Ajuda e suporte"), respondido na área admin. */
class SupportTicket extends Model
{
    public const STATUS_OPEN = 'open';           // com o suporte (chamado novo ou a loja respondeu)

    public const STATUS_ANSWERED = 'answered';   // o suporte respondeu, aguardando a loja

    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_ANSWERED, self::STATUS_RESOLVED];

    public const CATEGORIES = ['problem', 'question', 'suggestion', 'billing'];

    public const CATEGORY_LABELS = [
        'problem' => 'Problema',
        'question' => 'Dúvida',
        'suggestion' => 'Sugestão',
        'billing' => 'Assinatura e cobrança',
    ];

    protected $fillable = [
        'company_id', 'user_id', 'category', 'subject', 'status', 'page_url', 'context',
        'unread_by_user', 'unread_by_admin', 'last_message_at', 'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'unread_by_user' => 'boolean',
        'unread_by_admin' => 'boolean',
        'last_message_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }
}
