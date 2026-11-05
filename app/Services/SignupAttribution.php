<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Origem do cadastro (o que a landing gravou no cookie mct_src, ou as etiquetas do próprio link do cadastro) e o
 * canal classificado pra área admin comparar cadastros e assinaturas por origem.
 */
class SignupAttribution
{
    public const CHANNELS = [
        'google_ads', 'meta_ads', 'organic_search', 'instagram', 'facebook', 'whatsapp', 'youtube', 'tiktok', 'email', 'referral_site', 'other', 'direct',
    ];

    /** Só os campos conhecidos, texto curto e sem espaço sobrando. */
    public static function clean(?array $raw): ?array
    {
        if (! $raw) {
            return null;
        }
        $data = [];
        foreach (['source', 'medium', 'campaign', 'term', 'content', 'referrer', 'landing_path'] as $key) {
            $value = trim(Str::limit((string) ($raw[$key] ?? ''), 150, ''));
            if ($value !== '') {
                $data[$key] = $value;
            }
        }
        if (! empty($raw['gclid'])) {
            $data['gclid'] = true; // só se veio de clique no Google Ads (o código em si não interessa)
        }
        if (! empty($raw['fbclid'])) {
            $data['fbclid'] = true;
        }
        if (! empty($raw['captured_at'])) {
            $data['captured_at'] = Str::limit((string) $raw['captured_at'], 40, '');
        }

        return $data ?: null;
    }

    public static function channel(?array $a): string
    {
        if (! $a) {
            return 'direct';
        }
        $source = Str::lower($a['source'] ?? '');
        $medium = Str::lower($a['medium'] ?? '');
        $referrer = Str::lower($a['referrer'] ?? '');
        $paid = in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid_social', 'paidsocial', 'ads', 'ad'], true);
        $from = fn (array $names) => collect($names)->contains(fn ($n) => str_contains($source, $n) || str_contains($referrer, $n));
        // Siglas curtas só valem como origem exata (senão "digital" contaria como Instagram)
        $is = fn (array $exact) => in_array($source, $exact, true);
        $meta = $from(['facebook', 'instagram']) || $is(['fb', 'ig', 'meta']);

        return match (true) {
            ! empty($a['gclid']) || ($paid && $from(['google', 'adwords'])) => 'google_ads',
            $paid && (! empty($a['fbclid']) || $meta) => 'meta_ads',
            $from(['instagram']) || $is(['ig']) => 'instagram',
            $from(['facebook']) || $is(['fb']) => 'facebook',
            $from(['whatsapp', 'wa.me']) => 'whatsapp',
            $from(['youtube', 'youtu.be']) => 'youtube',
            $from(['tiktok']) => 'tiktok',
            $medium === 'email' || $is(['email', 'newsletter']) => 'email',
            $source === '' && preg_match('/(^|\\.)(google|bing|yahoo|duckduckgo|ecosia)\\./', $referrer) === 1 => 'organic_search',
            $source !== '' => 'other',
            $referrer !== '' => 'referral_site',
            default => 'direct',
        };
    }
}
