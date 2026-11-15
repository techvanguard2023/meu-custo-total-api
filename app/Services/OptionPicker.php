<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Escolha das opções (grades) de um produto numa venda: confere o que veio (por id, ou por nome quando é o bot
 * do WhatsApp), exige as grades obrigatórias, não deixa escolher mais de uma opção numa grade "só uma" e devolve
 * a foto das opções — é o que fica guardado no item do pedido (nome e valores do momento da venda).
 */
class OptionPicker
{
    /**
     * @param  array<int, int|string>  $ids     ids de ProductOption
     * @param  array<int, string>  $labels      nomes: "Fosco" ou "Acabamento: Fosco" (sem acento, sem diferenciar maiúscula)
     * @return array<int, array{id: int, group: string, name: string, price_delta: float, cost_delta: float}>
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 422 com o que falta (o bot lê a mensagem)
     */
    public static function resolve(Product $product, array $ids = [], array $labels = []): array
    {
        /** @var Collection<int, ProductOptionGroup> $groups */
        $groups = $product->relationLoaded('optionGroups') ? $product->optionGroups : $product->optionGroups()->with('options')->get();
        $groups = $groups->each(fn (ProductOptionGroup $g) => $g->setRelation('options', $g->options->where('active', true)->values()));

        if ($groups->isEmpty()) {
            // Produto sem grades: o que vier de opções é ignorado (o bot às vezes manda por engano)
            return [];
        }

        $chosen = collect();
        foreach ($ids as $id) {
            $option = $groups->flatMap->options->firstWhere('id', (int) $id);
            abort_unless($option, 422, "Opção inválida para o produto \"{$product->name}\". ".self::available($groups));
            $chosen->put($option->id, $option);
        }
        foreach ($labels as $label) {
            $option = self::byLabel($groups, (string) $label);
            abort_unless($option, 422, "Opção \"{$label}\" não existe em \"{$product->name}\". ".self::available($groups));
            $chosen->put($option->id, $option);
        }

        $snapshot = [];
        foreach ($groups as $group) {
            $picked = $chosen->filter(fn (ProductOption $o) => $o->product_option_group_id === $group->id)->values();

            abort_if($group->type === ProductOptionGroup::SINGLE && $picked->count() > 1, 422, "Escolha só uma opção de \"{$group->name}\" em \"{$product->name}\".");
            abort_if(
                $group->required && $picked->isEmpty(),
                422,
                "Escolha uma opção de \"{$group->name}\" para o produto \"{$product->name}\". Opções: ".$group->options->map(fn ($o) => $o->name)->implode(', ').'.'
            );

            foreach ($picked as $option) {
                $snapshot[] = [
                    'id' => $option->id,
                    'group' => $group->name,
                    'name' => $option->name,
                    'price_delta' => (float) $option->price_delta,
                    'cost_delta' => (float) $option->cost_delta,
                ];
            }
        }

        return $snapshot;
    }

    /** Soma do que as opções acrescentam por unidade. */
    public static function priceDelta(array $snapshot): float
    {
        return round(array_sum(array_column($snapshot, 'price_delta')), 2);
    }

    public static function costDelta(array $snapshot): float
    {
        return round(array_sum(array_column($snapshot, 'cost_delta')), 2);
    }

    /** "Acabamento: Fosco · Extras: Laço, Cartão" — o texto que vai no nome do item (pedido, PDF, mensagens). */
    public static function text(array $snapshot): string
    {
        return collect($snapshot)
            ->groupBy('group')
            ->map(fn ($rows, $group) => $group.': '.$rows->pluck('name')->implode(', '))
            ->implode(' · ');
    }

    private static function byLabel(Collection $groups, string $label): ?ProductOption
    {
        $normalize = fn (string $v) => mb_strtolower(trim(Str::ascii($v)));
        $wanted = $normalize($label);

        foreach ($groups as $group) {
            foreach ($group->options as $option) {
                $name = $normalize($option->name);
                $full = $normalize($group->name).': '.$name;
                $dash = $normalize($group->name).' - '.$name;
                if ($wanted === $name || $wanted === $full || $wanted === $dash) {
                    return $option;
                }
            }
        }

        return null;
    }

    private static function available(Collection $groups): string
    {
        return 'Opções: '.$groups->map(fn ($g) => $g->name.' ('.$g->options->pluck('name')->implode(', ').')')->implode('; ').'.';
    }
}
