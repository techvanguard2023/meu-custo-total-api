<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'company_id'])]
#[Hidden(['password', 'remember_token', 'terms_accepted_ip'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Vai junto no JSON do usuário: o frontend mostra as configurações técnicas só pra quem é da plataforma,
     * e pede o aceite dos termos quando a versão aceita não é a vigente.
     */
    protected $appends = ['is_platform_admin', 'needs_terms_acceptance'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Conta da equipe da plataforma (PLATFORM_ADMIN_EMAILS) — não é o dono da empresa. */
    public function isPlatformAdmin(): bool
    {
        return in_array(strtolower($this->email), config('services.platform.admin_emails', []), true);
    }

    protected function getIsPlatformAdminAttribute(): bool
    {
        return $this->isPlatformAdmin();
    }

    protected function getNeedsTermsAcceptanceAttribute(): bool
    {
        return $this->terms_version !== config('legal.terms_version');
    }

    /** Registra o aceite da versão vigente dos Termos de Uso e da Política de Privacidade. */
    public function acceptTerms(?string $ip): void
    {
        $this->forceFill([
            'terms_accepted_at' => now(),
            'terms_version' => config('legal.terms_version'),
            'terms_accepted_ip' => $ip,
        ])->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
