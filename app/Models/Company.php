<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Billable;

class Company extends Model
{
    use Billable;

    protected static function booted(): void
    {
        // Domínio próprio é do plano Pro: ao sair dele, o domínio é descadastrado da Vercel e o link padrão volta a valer.
        static::updated(function (Company $company) {
            if ($company->wasChanged('plan') && $company->plan !== self::PLAN_PRO && $company->custom_domain) {
                try {
                    app(\App\Services\VercelDomains::class)->remove($company->custom_domain);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Domínio próprio não removido da Vercel ({$company->custom_domain}): ".$e->getMessage());
                }
                $company->forceFill(['custom_domain' => null, 'custom_domain_verified_at' => null])->saveQuietly();
            }
        });
    }

    public const PLAN_FREE = 'free';
    public const PLAN_ESSENTIAL = 'essential';
    public const PLAN_PRO = 'pro';

    protected $fillable = [
        'name', 'slug', 'plan', 'email', 'phone', 'currency', 'timezone',
        'catalog_token', 'catalog_enabled', 'catalog_whatsapp', 'catalog_disclaimer', 'logo_path',
        'catalog_instagram_url', 'catalog_facebook_url', 'catalog_youtube_url',
        'catalog_tiktok_url', 'catalog_linkedin_url',
        'catalog_about', 'catalog_address', 'catalog_hours', 'catalog_email',
        'catalog_accent_color', 'custom_domain', 'custom_domain_verified_at',
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
    ];

    protected $appends = ['logo_url'];

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

    /** Área do parceiro (lojas com expositor): no domínio próprio quando ativo (/parceiro), senão no app. */
    public function partnerPortalUrl(): string
    {
        return $this->hasActiveCustomDomain()
            ? $this->publicOrigin().'/parceiro'
            : $this->publicOrigin().'/parceiro/'.$this->slug;
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

    public function materialCategories(): HasMany
    {
        return $this->hasMany(MaterialCategory::class);
    }
}
