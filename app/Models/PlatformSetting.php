<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Liga/desliga da plataforma (área admin → Sistema). Lido a cada requisição, por isso fica no cache. */
class PlatformSetting extends Model
{
    /** Mostrar a integração com a Alexa (card em Integrações) para os lojistas. Admins sempre veem. */
    public const ALEXA_VISIBLE = 'alexa_visible';

    public const DEFAULTS = [
        self::ALEXA_VISIBLE => false,
    ];

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
