<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Visão geral do admin → "De onde vêm as lojas": cadastros dos últimos 90 dias por canal, quantos assinaram (a
 * primeira assinatura depois do cadastro, mesmo que tenha cancelado depois) e quantos ainda estão no teste grátis.
 * Lojas de antes da medição aparecem como "unknown".
 */
class SignupChannelStats
{
    public const WINDOW_DAYS = 90;

    public function summary(): array
    {
        $rows = Company::query()
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->select(['id', 'signup_channel', 'signup_attribution', 'created_at', 'pro_trial_ends_at'])
            ->addSelect([
                'first_subscription_at' => DB::table('subscriptions')->selectRaw('MIN(created_at)')
                    ->whereColumn('subscriptions.company_id', 'companies.id')->where('type', 'default'),
            ])
            ->get()
            ->map(fn (Company $c) => (object) [
                'channel' => $c->signup_channel ?: 'unknown',
                'campaign' => $c->signup_attribution['campaign'] ?? null,
                'subscribed' => $c->first_subscription_at !== null && $c->first_subscription_at >= $c->created_at->copy()->subMinute()->toDateTimeString(),
                'in_trial' => $c->first_subscription_at === null && $c->pro_trial_ends_at?->isFuture(),
            ]);

        $group = fn ($items) => [
            'signups' => $items->count(),
            'subscribed' => $items->where('subscribed', true)->count(),
            'in_trial' => $items->where('in_trial', true)->count(),
            'rate' => $items->count() ? round($items->where('subscribed', true)->count() / $items->count() * 100, 1) : null,
        ];

        return [
            'window_days' => self::WINDOW_DAYS,
            'channels' => $rows->groupBy('channel')->map(fn ($items, $channel) => ['channel' => $channel] + $group($items))
                ->sortByDesc('signups')->values()->all(),
            // Campanhas do Google Ads (utm_campaign do link do anúncio, quando houver)
            'google_campaigns' => $rows->where('channel', 'google_ads')->groupBy(fn ($r) => $r->campaign ?: '(sem nome de campanha)')
                ->map(fn ($items, $campaign) => ['campaign' => $campaign] + $group($items))
                ->sortByDesc('signups')->take(8)->values()->all(),
        ];
    }
}
