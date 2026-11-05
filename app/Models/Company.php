<?php

namespace App\Models;

use App\Models\Concerns\BustsCatalogCache;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Billable;

class Company extends Model
{
    use Billable;
    use BustsCatalogCache;

    /** Catálogo público da própria loja (cache limpo ao gravar). */
    public function catalogCompanyId(): ?int
    {
        return $this->id;
    }

    protected static function booted(): void
    {
        // Domínio próprio é do plano Pro: ao sair dele, o domínio é descadastrado da Vercel e o link padrão volta a valer.
        // Vale pros três: o do catálogo, o da área do parceiro e o das ofertas.
        static::updated(function (Company $company) {
            if (! $company->wasChanged('plan') || $company->plan === self::PLAN_PRO) {
                return;
            }

            foreach (self::DOMAIN_COLUMNS as $column) {
                if (! $company->{$column}) {
                    continue;
                }
                try {
                    app(\App\Services\VercelDomains::class)->remove($company->{$column});
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Domínio próprio não removido da Vercel ({$company->{$column}}): ".$e->getMessage());
                }
                $company->forceFill([$column => null, "{$column}_verified_at" => null])->saveQuietly();
            }
        });
    }

    /** Nicho da empresa: muda o vocabulário das telas e as categorias padrão. */
    public const SEGMENT_3D = '3d_printing';
    public const SEGMENTS = [self::SEGMENT_3D, 'crafts', 'stationery', 'bakery', 'other'];

    public const PLAN_FREE = 'free';
    public const PLAN_ESSENTIAL = 'essential';
    public const PLAN_PRO = 'pro';

    protected $fillable = [
        'name', 'slug', 'plan', 'segment', 'email', 'phone', 'currency', 'timezone',
        'catalog_token', 'catalog_enabled', 'catalog_whatsapp', 'catalog_disclaimer', 'logo_path',
        'catalog_instagram_url', 'catalog_facebook_url', 'catalog_youtube_url',
        'catalog_tiktok_url', 'catalog_linkedin_url',
        'catalog_about', 'catalog_address', 'catalog_hours', 'catalog_email',
        'catalog_accent_color', 'custom_domain', 'custom_domain_verified_at',
        'partner_domain', 'partner_domain_verified_at', 'offers_domain', 'offers_domain_verified_at',
        'whatsapp_session_name', 'whatsapp_bot_enabled', 'whatsapp_webhook_url', 'whatsapp_webhook_events',
        'whatsapp_chat_filters', 'whatsapp_status_notifications', 'whatsapp_bot_prompt', 'whatsapp_payment_link', 'whatsapp_pix_key',
    ];

    protected $casts = [
        'catalog_enabled' => 'boolean',
        'whatsapp_bot_enabled' => 'boolean',
        'whatsapp_webhook_events' => 'array',
        'whatsapp_chat_filters' => 'array',
        'whatsapp_status_notifications' => 'array',
        'custom_domain_verified_at' => 'datetime',
        'partner_domain_verified_at' => 'datetime',
        'offers_domain_verified_at' => 'datetime',
        'deletion_scheduled_for' => 'datetime',
        'blocked_at' => 'datetime',
        'comp_until' => 'date:Y-m-d',
        'admin_discount' => 'array',
        'deletion_paused_subscription' => 'boolean',
    ];

    protected $appends = ['logo_url'];

    /**
     * Fora do JSON que vai pro navegador (/me, login...): anotações internas da plataforma e dados técnicos
     * (webhook, token antigo do catálogo, Stripe). As telas que precisam deles têm rota própria.
     */
    protected $hidden = [
        'admin_notes', 'comp_note', 'admin_discount',
        'whatsapp_webhook_url', 'whatsapp_webhook_events', 'catalog_token',
        'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at', 'deletion_paused_subscription',
    ];

    /** Quantas pessoas (contando o dono) o plano comporta. */
    public const USER_LIMITS = [
        self::PLAN_FREE => 1,
        self::PLAN_ESSENTIAL => 2,
        self::PLAN_PRO => 5,
    ];

    public function userLimit(): int
    {
        return self::USER_LIMITS[$this->plan] ?? 1;
    }

    /** Exclusão da conta pedida e ainda no prazo de desistência: painel, catálogo e bot ficam fora do ar. */
    public function isPendingDeletion(): bool
    {
        return $this->deletion_scheduled_for !== null;
    }

    /**
     * Só lojas ativas — catálogo, área do parceiro, QR, "Meus pedidos" e envios automáticos somem durante o
     * prazo de exclusão e enquanto a loja estiver bloqueada pela plataforma.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deletion_scheduled_for')->whereNull('blocked_at');
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /** Plano de cortesia valendo hoje (sem data = sem prazo). */
    public function activeCompPlan(): ?string
    {
        if (! $this->comp_plan || ($this->comp_until && $this->comp_until->lt(now()->startOfDay()))) {
            return null;
        }

        return $this->comp_plan;
    }

    /**
     * Plano que a loja deve ter: o da assinatura Stripe ou o de cortesia, o maior dos dois. Usado pelo
     * webhook da Stripe e pelo plan:sync — assim a sincronização nunca desfaz uma cortesia.
     */
    public function planFromBilling(): string
    {
        $rank = [self::PLAN_FREE => 0, self::PLAN_ESSENTIAL => 1, self::PLAN_PRO => 2];
        $subscription = self::planForSubscription($this->subscription('default'));
        $comp = $this->activeCompPlan();

        return $comp && $rank[$comp] > $rank[$subscription] ? $comp : $subscription;
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null
        );
    }

    public function isPro(): bool
    {
        return $this->plan === self::PLAN_PRO;
    }

    /** Domínio próprio do catálogo em uso: Pro, com o DNS já apontando certo. */
    public function hasActiveCustomDomain(): bool
    {
        return $this->isPro() && $this->custom_domain && $this->custom_domain_verified_at !== null;
    }

    /** Raiz dos links públicos da loja (catálogo, avaliação): o domínio próprio quando ativo, senão o do app. */
    public function publicOrigin(): string
    {
        return $this->hasActiveCustomDomain()
            ? 'https://'.$this->custom_domain
            : rtrim((string) config('services.frontend_url'), '/');
    }

    /** Endereço do catálogo: na raiz do domínio próprio, ou em /catalog/{slug} no app. */
    public function catalogUrl(): string
    {
        return $this->hasActiveCustomDomain()
            ? $this->publicOrigin()
            : $this->publicOrigin().'/catalog/'.$this->slug;
    }

    /** Domínio próprio da área do parceiro em uso (parceiro.empresa.com.br): Pro, com o DNS já apontando certo. */
    public function hasActivePartnerDomain(): bool
    {
        return $this->isPro() && $this->partner_domain && $this->partner_domain_verified_at !== null;
    }

    /**
     * Área do parceiro (lojas com expositor): no domínio próprio dela quando ativo; senão em /parceiro do
     * domínio do catálogo; senão no app.
     */
    public function partnerPortalUrl(): string
    {
        if ($this->hasActivePartnerDomain()) {
            return 'https://'.$this->partner_domain;
        }

        return $this->hasActiveCustomDomain()
            ? $this->publicOrigin().'/parceiro'
            : $this->publicOrigin().'/parceiro/'.$this->slug;
    }

    /** Domínios próprios da loja (cada um com a coluna "{coluna}_verified_at"). Um endereço serve a uma coisa só. */
    public const DOMAIN_COLUMNS = ['custom_domain', 'partner_domain', 'offers_domain'];

    public function hasActiveOffersDomain(): bool
    {
        return $this->isPro() && $this->offers_domain && $this->offers_domain_verified_at !== null;
    }

    /**
     * Onde ficam os links fixos das ofertas (/ofertas/{slug}): no domínio próprio das ofertas quando ativo;
     * senão no domínio do catálogo; senão no app.
     */
    public function offersOrigin(): string
    {
        return $this->hasActiveOffersDomain() ? 'https://'.$this->offers_domain : $this->publicOrigin();
    }

    /** Essencial ou Pro — os dois planos pagos, usado pelos recursos que não são exclusivos do topo. */
    public function hasEssential(): bool
    {
        return in_array($this->plan, [self::PLAN_ESSENTIAL, self::PLAN_PRO], true);
    }

    /** Catálogo público só fica de fato acessível se estiver ligado E a empresa ainda for Essencial/Pro. */
    public function hasCatalogActive(): bool
    {
        return $this->catalog_enabled && $this->catalog_token && $this->hasEssential();
    }

    /**
     * Qual plano corresponde à assinatura Stripe local — usada pelo webhook e pelo
     * comando plan:sync, pra nunca rebaixar quem tem assinatura ativa num preço que
     * a gente não reconhece (ex: criado direto no painel da Stripe).
     */
    public static function planForSubscription(?\Laravel\Cashier\Subscription $subscription): string
    {
        if (! $subscription || ! $subscription->valid()) {
            return self::PLAN_FREE;
        }

        return match ($subscription->stripe_price) {
            config('services.stripe.price_pro') => self::PLAN_PRO,
            config('services.stripe.price_essential') => self::PLAN_ESSENTIAL,
            default => self::PLAN_PRO,
        };
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function quoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function setting(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Setting::class);
    }

    public function banners(): HasMany
    {
        return $this->hasMany(CatalogBanner::class)->orderBy('position');
    }

    public function productCollections(): HasMany
    {
        return $this->hasMany(ProductCollection::class);
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function salesChannels(): HasMany
    {
        return $this->hasMany(SalesChannel::class);
    }

    public function displays(): HasMany
    {
        return $this->hasMany(Display::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function materialCategories(): HasMany
    {
        return $this->hasMany(MaterialCategory::class);
    }
}
