<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Liga/desliga da plataforma (área admin → Sistema). Lido a cada requisição, por isso fica no cache. */
class PlatformSetting extends Model
{
    /** Mostrar a integração com a Alexa (card em Integrações) para os lojistas. Admins sempre veem. */
    public const ALEXA_VISIBLE = 'alexa_visible';

    /** Mostrar o card de cada gateway de pagamento em Integrações para os lojistas. Admins sempre veem. */
    public const MERCADOPAGO_VISIBLE = 'mercadopago_visible';

    public const PAGBANK_VISIBLE = 'pagbank_visible';

    /**
     * Nome da sessão da WAHA do WhatsApp da plataforma (suporte, mensagens do admin), criada em Admin → Sistema.
     * Vazio = usa PLATFORM_WAHA_SESSION do .env (como era antes de dar para conectar pelo painel).
     */
    public const PLATFORM_WAHA_SESSION = 'platform_waha_session';

    public const DEFAULTS = [
        self::ALEXA_VISIBLE => false,
        self::MERCADOPAGO_VISIBLE => true,
        self::PAGBANK_VISIBLE => true,
    ];

    /**
     * O gateway aparece para este usuário? Escondido no admin, só os admins da plataforma veem (para testar). A
     * loja que já conectou continua vendo o card (cobra e pode desconectar); só não dá para conectar de novo.
     */
    public static function gatewayVisible(string $provider, ?User $user): bool
    {
        if ($user?->isPlatformAdmin()) {
            return true;
        }
        $key = match ($provider) {
            'mercadopago' => self::MERCADOPAGO_VISIBLE,
            'pagbank' => self::PAGBANK_VISIBLE,
            default => null,
        };

        return $key === null || (bool) self::get($key);
    }

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'json'];

    public static function get(string $key): mixed
    {
        $value = Cache::rememberForever(self::cacheKey($key), fn () => ['v' => static::where('key', $key)->value('value')])['v'];

        return $value ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'platform-setting:'.$key;
    }
}
