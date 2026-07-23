<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

class Company extends Model
{
    use Billable;

    public const PLAN_FREE = 'free';
    public const PLAN_PRO = 'pro';

    protected $fillable = [
        'name', 'slug', 'plan', 'email', 'phone', 'currency', 'timezone',
        'catalog_token', 'catalog_enabled',
    ];

    protected $casts = [
        'catalog_enabled' => 'boolean',
    ];

    public function isPro(): bool
    {
        return $this->plan === self::PLAN_PRO;
    }

    /** Catálogo público só fica de fato acessível se estiver ligado E a empresa ainda for Pro. */
    public function hasCatalogActive(): bool
    {
        return $this->catalog_enabled && $this->catalog_token && $this->isPro();
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

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function setting(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Setting::class);
    }
}
