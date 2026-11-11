<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Tour;
use App\Models\User;
use App\Models\UserTour;

/**
 * Tours do app (react-joyride): o que cada pessoa deve ver agora.
 *  - full: a loja tem o plano da feature e ainda não viu esta versão do tour;
 *  - preview: a loja não tem o plano — prévia curta com "Ver planos", uma vez por versão;
 *  - null: nada a mostrar.
 * A marcação fica no servidor, por pessoa. Quem está no teste grátis do Pro vê o tour uma vez e não vê de novo;
 * a prévia do gratuito não impede o tour completo depois do upgrade (são marcas separadas).
 */
class Tours
{
    public const FULL = 'full';

    public const PREVIEW = 'preview';

    private const RANK = ['free' => 0, 'essential' => 1, 'pro' => 2];

    /**
     * Tours cadastrados na área admin (tabela tours), por slug. Por padrão só os ligados.
     *
     * @return array<string, array{title: string, plan: string, version: int, route: string, steps: array, preview: array, active: bool}>
     */
    public static function definitions(bool $withInactive = false): array
    {
        return Tour::query()
            ->when(! $withInactive, fn ($q) => $q->where('active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Tour $tour) => [$tour->slug => [
                'title' => $tour->title,
                'plan' => $tour->plan,
                'version' => $tour->version,
                'route' => $tour->route,
                'steps' => array_values($tour->steps ?? []),
                'preview' => ['title' => $tour->preview_title, 'content' => $tour->preview_content],
                'active' => $tour->active,
            ]])
            ->all();
    }

    public static function planOf(Company $company): string
    {
        return $company->isPro() ? 'pro' : ($company->hasEssential() ? 'essential' : 'free');
    }

    /** A loja tem o plano que a feature exige? */
    public static function eligible(Company $company, string $requiredPlan): bool
    {
        return self::RANK[self::planOf($company)] >= self::RANK[$requiredPlan];
    }

    /**
     * Os tours com os passos (o app monta a partir daqui) e o que a pessoa ainda precisa ver.
     * O admin da plataforma recebe também os desligados (sem aparecer sozinho), pra testar antes de ligar.
     *
     * @return list<array{id: string, title: string, version: int, required_plan: string, mode: ?string, route: string, steps: array, preview: array, active: bool}>
     */
    public static function status(User $user): array
    {
        $company = $user->company;
        $plan = self::planOf($company);
        $seenKeys = UserTour::query()->where('user_id', $user->id)->get(['tour_id', 'version', 'kind'])
            ->map(fn ($s) => "{$s->tour_id}|{$s->version}|{$s->kind}")->flip();
        $list = [];

        foreach (self::definitions($user->isPlatformAdmin()) as $id => $tour) {
            $mode = self::eligible($company, $tour['plan']) ? self::FULL : self::PREVIEW;
            $seen = $seenKeys->has("{$id}|{$tour['version']}|{$mode}");

            $list[] = [
                'id' => $id,
                'title' => $tour['title'],
                'version' => $tour['version'],
                'required_plan' => $tour['plan'],
                'plan' => $plan,
                'mode' => ($seen || ! $tour['active']) ? null : $mode,
                'route' => $tour['route'],
                'steps' => $tour['steps'],
                'preview' => $tour['preview'],
                'active' => $tour['active'],
            ];
        }

        return $list;
    }

    public static function seen(User $user, string $tourId, int $version, string $kind): bool
    {
        return UserTour::query()
            ->where('user_id', $user->id)
            ->where('tour_id', $tourId)
            ->where('version', $version)
            ->where('kind', $kind)
            ->exists();
    }

    /** Marca como visto (repetir não dá erro). */
    public static function markSeen(User $user, string $tourId, int $version, string $kind): void
    {
        UserTour::query()->insertOrIgnore([
            'user_id' => $user->id,
            'tour_id' => $tourId,
            'version' => $version,
            'kind' => $kind,
            'seen_at' => now(),
        ]);
    }
}
