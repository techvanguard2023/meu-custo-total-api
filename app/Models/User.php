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
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Vai junto no JSON do usuário: o frontend mostra as configurações técnicas só pra quem é da plataforma. */
    protected $appends = ['is_platform_admin'];

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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
