<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Série de divulgação de ofertas: grupos "Nome #1, #2..." (abre o próximo ao lotar) ou um canal do WhatsApp. */
class OfferSeries extends Model
{
    public const KIND_GROUP = 'group';
    public const KIND_CHANNEL = 'channel';

    protected $table = 'offer_series';

    public const PURPOSE_STORE = 'store';       // produtos da loja (link do catálogo)

    public const PURPOSE_AFFILIATE = 'affiliate'; // achadinhos de outras lojas (link de afiliado)

    protected $fillable = ['company_id', 'kind', 'purpose', 'affiliate_disclosure', 'name', 'description', 'image_path', 'image_all_groups', 'slug', 'capacity', 'backup_admin_phone', 'channel_jid', 'channel_invite', 'active', 'coupon_id'];

    protected $casts = [
        'active' => 'boolean',
        'capacity' => 'integer',
        'image_all_groups' => 'boolean',
        'affiliate_disclosure' => 'boolean',
    ];

    public function isAffiliate(): bool
    {
        return $this->purpose === self::PURPOSE_AFFILIATE;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Cupom exclusivo da série: vai em cada oferta (texto + link que já aplica no carrinho). */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(OfferGroup::class)->orderBy('number');
    }

    /** Grupo que recebe quem entra pelo link fixo: o mais novo (os anteriores já lotaram). */
    public function currentGroup(): ?OfferGroup
    {
        return $this->groups()->reorder('number', 'desc')->first();
    }

    public function isChannel(): bool
    {
        return $this->kind === self::KIND_CHANNEL;
    }

    /** Nome do grupo de número N ("Ofertas da Loja #2"). */
    public function groupName(int $number): string
    {
        return "{$this->name} #{$number}";
    }
}
