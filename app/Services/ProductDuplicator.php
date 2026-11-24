<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Botão "Duplicar" das listas de Produtos e Materiais: cria uma cópia com todas as informações para o lojista
 * só ajustar o que muda (outra cor, outro tamanho...).
 *
 * O que não vem junto, de propósito: o estoque (a cópia começa com zero, porque o estoque físico é do item
 * original), o código (SKU, que é único), as avaliações, as vendas e os expositores. O produto copiado começa
 * fora do catálogo, para "Nome (cópia)" não aparecer para o cliente antes de ser ajustado.
 */
class ProductDuplicator
{
    public function __construct(private ProductRecipe $recipe) {}

    public function duplicateProduct(Product $original): Product
    {
        $original->loadMissing(['variations', 'recipeLines', 'optionGroups.options', 'images', 'collections']);

        return DB::transaction(function () use ($original) {
            $data = collect($original->getAttributes())->only($original->getFillable())->all();
            $data['name'] = self::copyName($original->name);
            $data['slug'] = Product::uniqueSlug($data['name'], $original->company_id);
            $data['sku'] = null;
            $data['stock_quantity'] = $original->stock_quantity === null ? null : 0;
            $data['show_in_catalog'] = false;
            $data['featured'] = false;
            $data['image_path'] = $this->copyFile($original->image_path);

            $copy = new Product;
            $copy->forceFill($data)->save();

            // Variações: guarda o id novo de cada uma, para a ficha técnica específica apontar para a cópia certa
            $variationIds = [];
            foreach ($original->variations as $variation) {
                $new = $copy->variations()->create(array_merge(
                    collect($variation->getAttributes())->only($variation->getFillable())->except('product_id')->all(),
                    ['sku' => null, 'stock_quantity' => $variation->stock_quantity === null ? null : 0]
                ));
                $variationIds[$variation->id] = $new->id;
            }

            foreach ($original->recipeLines as $line) {
                $copy->recipeLines()->create([
                    'product_variation_id' => $line->product_variation_id ? ($variationIds[$line->product_variation_id] ?? null) : null,
                    'material_id' => $line->material_id,
                    'quantity' => $line->quantity,
                ]);
            }

            foreach ($original->optionGroups as $group) {
                $newGroup = $copy->optionGroups()->create(
                    collect($group->getAttributes())->only($group->getFillable())->except('product_id')->all()
                );
                foreach ($group->options as $option) {
                    $newGroup->options()->create(
                        collect($option->getAttributes())->only($option->getFillable())->except('product_option_group_id')->all()
                    );
                }
            }

            // Fotos: arquivo copiado (apagar a foto de um não some com a do outro). A imagem de compartilhamento
            // é gerada de novo pela rotina de sempre.
            foreach ($original->images as $image) {
                $path = $this->copyFile($image->path);
                if ($path) {
                    $copy->images()->create(['path' => $path, 'share_path' => null, 'position' => $image->position]);
                }
            }

            $copy->collections()->sync($original->collections->pluck('id'));
            $this->recipe->refreshCost($copy);

            return $copy;
        });
    }

    public function duplicateMaterial(Material $original): Material
    {
        $data = collect($original->getAttributes())->only($original->getFillable())->all();
        $data['name'] = self::copyName($original->name);
        $data['stock_quantity'] = $original->stock_quantity === null ? null : 0;

        $copy = new Material;
        $copy->forceFill($data)->save();

        return $copy;
    }

    public static function copyName(string $name): string
    {
        return Str::limit($name, 245, '').' (cópia)';
    }

    private function copyFile(?string $path): ?string
    {
        $disk = Storage::disk('public');
        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $target = trim(dirname($path), './').'/'.Str::random(40).($extension ? ".{$extension}" : '');
        $disk->copy($path, ltrim($target, '/'));

        return ltrim($target, '/');
    }
}
