<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['company_id', 'name', 'phone', 'email', 'notes'];

    /**
     * Chave pra reconhecer o mesmo telefone escrito de jeitos diferentes:
     * "(21) 97527-8220", "21975278220", "5521975278220" ou o formato antigo do
     * WhatsApp sem o nono dígito ("552175278220"). Tira o DDI 55 e fica com
     * DDD + os 8 últimos dígitos. Números curtos (sem DDD) ou de fora do Brasil
     * comparam pelos dígitos inteiros.
     */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 10 ? substr($digits, 0, 2).substr($digits, -8) : $digits;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
