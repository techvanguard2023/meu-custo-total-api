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

    /** Perfis da equipe: o dono vê e faz tudo; vendas e produção têm acesso parcial (ver App\Services\TeamAccess). */
    public const ROLE_OWNER = 'owner';
    public const ROLE_SALES = 'sales';
    public const ROLE_PRODUCTION = 'production';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_SALES, self::ROLE_PRODUCTION];

    /** Senha forte (cadastro e convite) — mesmas regras de src/lib/passwordRules.ts no frontend. */
    public const STRONG_PASSWORD = ['required', 'string', 'min:8', 'regex:/\p{Ll}/u', 'regex:/\p{Lu}/u', 'regex:/\p{N}/u', 'regex:/[^\p{L}\p{N}\s]/u'];

    public const STRONG_PASSWORD_MESSAGES = [
        'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
        'password.regex' => 'A senha precisa ter letra minúscula, letra maiúscula, número e caractere especial.',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /** Custos, lucro e margens: só o dono. */
    public function seesCosts(): bool
    {
        return $this->isOwner();
    }

    /** Preços e valores de venda: dono e vendas (a produção trabalha sem valores). */
    public function seesPrices(): bool
    {
        return $this->role !== self::ROLE_PRODUCTION;
    }

    /**
     * Plano rebaixado com mais gente do que comporta: os donos continuam entrando, e da equipe só os
     * mais antigos cabem no limite. Os outros ficam parados até o dono subir o plano ou remover alguém.
     */
    public function isBlockedByPlanLimit(): bool
    {
        if ($this->isOwner() || ! $this->company) {
            return false;
        }

        $owners = $this->company->users()->where('role', self::ROLE_OWNER)->count();
        $staffSlots = max(0, $this->company->userLimit() - $owners);

        return ! $this->company->users()
            ->where('role', '!=', self::ROLE_OWNER)
            ->orderBy('id')
            ->limit($staffSlots)
            ->pluck('id')
            ->contains($this->id);
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
            'invited_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
