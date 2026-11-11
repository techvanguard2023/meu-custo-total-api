<?php

namespace App\Services;

use App\Models\Company;
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

    /** @return array<string, array{title: string, plan: string, version: int}> */
    public static function definitions(): array
    {
        return config('tours.tours', []);
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

    /** @return list<array{id: string, title: string, version: int, required_plan: string, mode: ?string}> */
    public static function status(User $user): array
    {
        $company = $user->company;
        $list = [];

        foreach (self::definitions() as $id => $tour) {
            $eligible = self::eligible($company, $tour['plan']);
            $mode = $eligible ? self::FULL : self::PREVIEW;
            $seen = self::seen($user, $id, $tour['version'], $mode);

            $list[] = [
                'id' => $id,
                'title' => $tour['title'],
                'version' => $tour['version'],
                'required_plan' => $tour['plan'],
                'plan' => self::planOf($company),
                'mode' => $seen ? null : $mode,
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
