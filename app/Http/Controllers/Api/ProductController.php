<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\Printer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOptionGroup;
use App\Models\ProductVariation;
use App\Services\ProductRecipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use EnforcesPlanLimits;

    /** Caracteres sem ambiguidade visual (sem 0/O, 1/I/L) pra código de produto legível. */
    private const SKU_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const SKU_LENGTH = 6;

    /** Cores por variação (ex: "Verde + Branco + Amarelo"); o mesmo limite está em src/pages/Products.tsx. */
    public const MAX_VARIATION_COLORS = 8;

    /** O que a tela de produtos precisa: o padrão (fotos, categoria, variações) e a ficha técnica — que só aparece aqui, nunca no catálogo público. */
    private const ADMIN_RELATIONS = [...Product::DISPLAY_RELATIONS, 'collections:id,name', 'recipeLines.material.category', 'activeDisplayLines.display:id,name'];

    public function index(Request $request)
    {
        return $request->user()->company->products()->with(self::ADMIN_RELATIONS)->latest()->get();
    }

    /** Sugere um código de produto único (dentro da empresa) pra preencher o campo SKU. */
    public function generateSku(Request $request)
    {
        return response()->json(['sku' => $this->generateUniqueSku($request->user()->company_id)]);
    }

    private function generateUniqueSku(int $companyId): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::SKU_LENGTH; $i++) {
                $code .= self::SKU_ALPHABET[random_int(0, strlen(self::SKU_ALPHABET) - 1)];
            }

            $exists = Product::where('company_id', $companyId)->where('sku', $code)->exists();
            if (! $exists) {
                return $code;
            }
        }

        throw new \RuntimeException('Não foi possível gerar um código único de produto.');
    }

    /** Slug único por empresa — a regra fica em Product::uniqueSlug (a importação de planilha usa a mesma). */
    private function generateUniqueSlug(string $name, int $companyId): string
    {
        return Product::uniqueSlug($name, $companyId);
    }

    public function store(Request $request)
    {
        $this->enforceFreeLimit($request, 'products', $request->user()->company->products()->count(), 'produtos');

        $data = $this->validated($request, $request->user()->company_id);
        $variations = $data['variations'] ?? null;
        $collectionIds = $data['collection_ids'] ?? null;
        $recipe = $data['recipe'] ?? null;
        $optionGroups = $data['option_groups'] ?? null;
        unset($data['variations'], $data['collection_ids'], $data['recipe'], $data['option_groups']);

        // Gerado uma vez, aqui — nunca a partir do input do cliente, e nunca
        // regerado depois. Se mudasse junto do nome, todo link já compartilhado
        // (WhatsApp, Instagram) apontando pra esse produto quebraria.
        $data['slug'] = $this->generateUniqueSlug($data['name'], $request->user()->company_id);

        $product = DB::transaction(function () use ($request, $data, $variations, $collectionIds, $recipe, $optionGroups) {
            $product = $request->user()->company->products()->create($data);
            $this->syncRecipe($product, null, $recipe);
            $this->syncVariations($product, $variations);
            $this->syncOptionGroups($product, $optionGroups);
            $this->syncCollections($product, $collectionIds);
            app(ProductRecipe::class)->refreshCost($product);

            return $product;
        });

        return response()->json($product->fresh()->load(self::ADMIN_RELATIONS), 201);
    }

    public function show(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);

        return $product->load(self::ADMIN_RELATIONS);
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);

        $data = $this->validated($request, $product->company_id, $product->id, $product);
        $variations = $data['variations'] ?? null;
        $collectionIds = $data['collection_ids'] ?? null;
        $recipe = $data['recipe'] ?? null;
        $optionGroups = $data['option_groups'] ?? null;
        unset($data['variations'], $data['collection_ids'], $data['recipe'], $data['option_groups']);

        DB::transaction(function () use ($product, $data, $variations, $collectionIds, $recipe, $optionGroups) {
            $product->update($data);
            $this->syncRecipe($product, null, $recipe);
            $this->syncVariations($product, $variations);
            $this->syncOptionGroups($product, $optionGroups);
            $this->syncCollections($product, $collectionIds);
            app(ProductRecipe::class)->refreshCost($product);
        });

        return $product->fresh()->load(self::ADMIN_RELATIONS);
    }

    /**
     * $collectionIds === null significa "campo não enviado" — mantém como está,
     * mesmo padrão de $variations === null em syncVariations().
     */
    private function syncCollections(Product $product, ?array $collectionIds): void
    {
        if ($collectionIds === null) {
            return;
        }

        // Só coleções da própria empresa — sem isso daria pra marcar um produto
        // numa coleção de outra empresa.
        $ownIds = $product->company->productCollections()->whereIn('id', $collectionIds)->pluck('id');
        $product->collections()->sync($ownIds);
        // Vínculo da coleção não dispara evento: o catálogo mostra as coleções
        \App\Services\CatalogCache::forget($product->company_id);
    }

    /**
     * Sincroniza as variações enviadas: atualiza as que já existem (pelo id),
     * cria as novas e remove as que sumiram do formulário.
     *
     * Atualizar em vez de recriar é essencial: quote_items apontam para a
     * variação, então apagar e recriar quebraria o vínculo do histórico de vendas
     * e, com nullOnDelete, faria a venda perder de qual variação ela era.
     *
     * $variations === null significa "campo não enviado" — mantém como está.
     */
    private function syncVariations(Product $product, ?array $variations): void
    {
        if ($variations === null) {
            return;
        }

        $keptIds = [];

        foreach (array_values($variations) as $position => $row) {
            $payload = [
                'color' => $row['color'] ?? null,
                // Maiúsculas, sem repetir; lista vazia vira null
                'color_hexes' => collect($row['color_hexes'] ?? [])->map(fn ($hex) => strtoupper($hex))->unique()->values()->all() ?: null,
                'size' => $row['size'] ?? null,
                'weight' => $row['weight'] ?? null,
                'sku' => $row['sku'] ?? null,
                'sale_price' => $row['sale_price'] ?? null,
                'cost' => $row['cost'] ?? null,
                'print_time_minutes' => $row['print_time_minutes'] ?? null,
                'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
                'active' => $row['active'] ?? true,
                'position' => $position,
            ];

            $existing = isset($row['id'])
                ? $product->variations()->whereKey($row['id'])->first()
                : null;

            if ($existing) {
                $existing->update($payload);
                $variation = $existing;
            } else {
                $variation = $product->variations()->create($payload);
            }

            $keptIds[] = $variation->id;
            $this->syncRecipe($product, $variation->id, $row['recipe'] ?? null);
        }

        $product->variations()->whereNotIn('id', $keptIds ?: [0])->delete();
        $product->unsetRelation('variations');
    }

    /**
     * Sincroniza as grades de opções enviadas: atualiza as que já existem (pelo id), cria as novas e remove as que
     * sumiram do formulário. O que foi vendido fica guardado no item do pedido (foto do momento), então apagar
     * uma grade ou opção não mexe em pedidos antigos. $groups === null = "campo não enviado": mantém como está.
     */
    private function syncOptionGroups(Product $product, ?array $groups): void
    {
        if ($groups === null) {
            return;
        }

        $keptGroupIds = [];
        foreach (array_values($groups) as $position => $row) {
            $payload = [
                'name' => trim($row['name']),
                'type' => $row['type'] ?? ProductOptionGroup::SINGLE,
                'required' => (bool) ($row['required'] ?? false),
                'position' => $position,
            ];
            $existing = isset($row['id']) ? $product->optionGroups()->whereKey($row['id'])->first() : null;
            $group = $existing ?? $product->optionGroups()->create($payload);
            if ($existing) {
                $existing->update($payload);
            }
            $keptGroupIds[] = $group->id;

            $keptOptionIds = [];
            foreach (array_values($row['options'] ?? []) as $optionPosition => $option) {
                $optionPayload = [
                    'name' => trim($option['name']),
                    'price_delta' => round((float) ($option['price_delta'] ?? 0), 2),
                    'cost_delta' => round((float) ($option['cost_delta'] ?? 0), 2),
                    'active' => (bool) ($option['active'] ?? true),
                    'position' => $optionPosition,
                ];
                $existingOption = isset($option['id']) ? $group->options()->whereKey($option['id'])->first() : null;
                if ($existingOption) {
                    $existingOption->update($optionPayload);
                    $keptOptionIds[] = $existingOption->id;
                } else {
                    $keptOptionIds[] = $group->options()->create($optionPayload)->id;
                }
            }
            $group->options()->whereNotIn('id', $keptOptionIds ?: [0])->delete();
        }

        $product->optionGroups()->whereNotIn('id', $keptGroupIds ?: [0])->delete();
        $product->unsetRelation('optionGroups');
    }

    /**
     * Substitui as linhas da ficha técnica — as comuns ($variationId nulo) ou as de uma variação.
     * $lines === null significa "campo não enviado" — mantém como está.
     */
    private function syncRecipe(Product $product, ?int $variationId, ?array $lines): void
    {
        if ($lines === null) {
            return;
        }

        $product->recipeLines()->where('product_variation_id', $variationId)->delete();

        foreach ($lines as $line) {
            $product->recipeLines()->create([
                'product_variation_id' => $variationId,
                'material_id' => $line['material_id'],
                'quantity' => $line['quantity'],
            ]);
        }

        $product->unsetRelation('recipeLines');
    }

    /**
     * Custo de uma unidade pela ficha técnica, ainda não salva — a tela mostra enquanto o
     * lojista monta a ficha. Mesmo cálculo do orçamento (insumos + energia + máquina + mão de obra + falha).
     */
    public function costPreview(Request $request, ProductRecipe $recipe)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'recipe' => ['present', 'array', 'max:30'],
            'recipe.*.material_id' => ['required', 'integer', Rule::exists('materials', 'id')->where('company_id', $companyId)],
            'recipe.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'print_time_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'postprocess_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'printer_id' => ['nullable', 'integer', Rule::exists('printers', 'id')->where('company_id', $companyId)],
        ]);

        $materials = Material::where('company_id', $companyId)->whereIn('id', collect($data['recipe'])->pluck('material_id'))->get()->keyBy('id');
        $lines = collect($data['recipe'])
            ->groupBy('material_id')
            ->map(fn ($group, $materialId) => ['material' => $materials->get($materialId), 'quantity' => (float) $group->sum('quantity')])
            ->values();

        return response()->json($recipe->breakdown(
            $lines,
            (int) ($data['print_time_minutes'] ?? 0),
            (int) ($data['postprocess_minutes'] ?? 0),
            isset($data['printer_id']) ? Printer::find($data['printer_id']) : null,
            $request->user()->company->setting,
        ));
    }

    /**
     * "Registrar produção": entram N unidades prontas no estoque do produto (ou da variação) e os
     * insumos da ficha técnica baixam. Insumo insuficiente não bloqueia — o saldo fica negativo
     * e a resposta lista o que faltou, pra tela avisar. Diferente de editar o estoque no
     * cadastro, que é só correção de contagem e não mexe nos insumos.
     */
    public function registerProduction(Request $request, Product $product, ProductRecipe $recipe)
    {
        $this->authorizeCompany($request, $product);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'product_variation_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $variationId = $data['product_variation_id'] ?? null;
        abort_if($product->variations->isNotEmpty() && ! $variationId, 422, 'Escolha qual variação foi produzida.');

        $shortages = DB::transaction(function () use ($product, $variationId, $data, $recipe) {
            $variation = $variationId
                ? ProductVariation::where('product_id', $product->id)->lockForUpdate()->find($variationId)
                : null;
            abort_if($variationId && ! $variation, 422, 'Variação não encontrada.');

            $target = $variation ?? Product::lockForUpdate()->find($product->id);
            $target->increment('stock_quantity', $data['quantity']);

            $label = $variation ? "{$product->name} ({$variation->display_name})" : $product->name;

            return $recipe->consume($product, $variation, (int) $data['quantity'], MaterialMovement::TYPE_PRODUCTION, [
                'note' => trim("Produção: {$data['quantity']}× {$label}".(! empty($data['note']) ? " — {$data['note']}" : '')),
            ]);
        });

        return response()->json([
            'product' => $product->fresh()->load(self::ADMIN_RELATIONS),
            'shortages' => $shortages,
        ]);
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);

        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        foreach ($product->images as $image) {
            Storage::disk('public')->delete(array_filter([$image->path, $image->share_path]));
        }

        $product->delete();

        return response()->noContent();
    }

    /** Upload de uma ou mais fotos, adicionadas ao final da galeria (máx. 8 por produto). */
    public function uploadImages(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);
        $this->requireEssential($request, 'Fotos do produto');

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $existingCount = $product->images()->count();
        abort_if($existingCount + count($request->file('images')) > 8, 422, 'Máximo de 8 fotos por produto.');

        $nextPosition = $existingCount;
        $optimizer = app(\App\Services\ImageOptimizer::class);
        foreach ($request->file('images') as $file) {
            // WebP leve pro catálogo + cópia JPEG pra prévia de link e WhatsApp
            $stored = $optimizer->storeProductImage($file);
            $product->images()->create(['path' => $stored['path'], 'share_path' => $stored['share_path'], 'position' => $nextPosition++]);
        }

        return response()->json($product->fresh(Product::DISPLAY_RELATIONS));
    }

    public function destroyImage(Request $request, Product $product, ProductImage $image)
    {
        $this->authorizeCompany($request, $product);
        abort_unless($image->product_id === $product->id, 404);

        Storage::disk('public')->delete(array_filter([$image->path, $image->share_path]));
        $image->delete();

        return response()->json($product->fresh(Product::DISPLAY_RELATIONS));
    }

    /** Reordena a galeria — recebe a lista de IDs das fotos na nova ordem. */
    public function reorderImages(Request $request, Product $product)
    {
        $this->authorizeCompany($request, $product);

        $data = $request->validate([
            'image_ids' => ['required', 'array'],
            'image_ids.*' => ['integer'],
        ]);

        $ids = $product->images()->pluck('id')->all();
        abort_unless(count(array_diff($ids, $data['image_ids'])) === 0 && count($data['image_ids']) === count($ids), 422, 'Lista de fotos inválida.');

        foreach ($data['image_ids'] as $position => $imageId) {
            ProductImage::where('id', $imageId)->where('product_id', $product->id)->update(['position' => $position]);
        }

        return response()->json($product->fresh(Product::DISPLAY_RELATIONS));
    }

    private function validated(Request $request, int $companyId, ?int $ignoreProductId = null, ?Product $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'nullable', 'string', 'max:255',
                Rule::unique('products', 'sku')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($ignoreProductId),
            ],
            'description' => ['nullable', 'string'],
            // Só as padrão do sistema e as da própria empresa — sem isso daria para
            // apontar um produto para a categoria privada de outra empresa.
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('product_categories', 'id')
                    ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId)),
            ],
            'model_3d_url' => ['nullable', 'url', 'max:2048'],
            'marketplace_mercado_livre_url' => ['nullable', 'url', 'max:2048'],
            'marketplace_shopee_url' => ['nullable', 'url', 'max:2048'],
            'marketplace_amazon_url' => ['nullable', 'url', 'max:2048'],
            'cost' => ['required', 'numeric', 'min:0'],
            // Ficha técnica: custo calculado pelos insumos + tempo de impressão (como no orçamento)
            'auto_cost' => ['sometimes', 'boolean'],
            // Preço de venda = sugerido pela ficha (custo + margem); só vale junto com o custo pela ficha
            'auto_price' => ['sometimes', 'boolean'],
            'print_time_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'postprocess_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'printer_id' => ['nullable', 'integer', Rule::exists('printers', 'id')->where('company_id', $companyId)],
            'recipe' => ['sometimes', 'array', 'max:30'],
            'recipe.*.material_id' => ['required', 'integer', Rule::exists('materials', 'id')->where('company_id', $companyId)],
            'recipe.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'made_to_order' => ['sometimes', 'boolean'],
            // Prazo de produção — só faz sentido pra item sob encomenda, mas não
            // bloqueia o cadastro se vier vazio (o lojista pode preencher depois).
            'lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'lead_time_days_type' => ['nullable', Rule::in(['business', 'calendar'])],
            'featured' => ['sometimes', 'boolean'],
            // Como é vendido: por unidade ou por pacote com N (preço e estoque são do pacote) e o pedido mínimo
            // (na unidade de venda). Vale pra todos os planos.
            'sale_unit' => ['sometimes', Rule::in(Product::SALE_UNITS)],
            'pack_size' => ['nullable', 'integer', 'min:2', 'max:9999', 'required_if:sale_unit,pack'],
            'pack_label' => ['nullable', 'string', 'max:20'],
            'min_quantity' => ['sometimes', 'integer', 'min:1', 'max:99999'],
            // Embalagem para o frete (vazio = padrão da loja): peso em gramas, medidas em cm
            'ship_weight_g' => ['nullable', 'integer', 'min:1', 'max:30000'],
            'ship_length_cm' => ['nullable', 'integer', 'min:1', 'max:105'],
            'ship_width_cm' => ['nullable', 'integer', 'min:1', 'max:105'],
            'ship_height_cm' => ['nullable', 'integer', 'min:1', 'max:105'],
            // Briefing da arte: o produto pede briefing (o bot manda o link após o pedido), com as perguntas de um
            // modelo da loja ou, sem modelo, as perguntas padrão
            'art_brief_enabled' => ['sometimes', 'boolean'],
            'art_brief_template_id' => ['nullable', 'integer', Rule::exists('art_brief_templates', 'id')->where('company_id', $companyId)],
            'discount_percent' => ['nullable', 'numeric', 'min:0.01', 'max:95'],
            // Preço por quantidade (Pro): "a partir de N un, cada uma sai por R$ X" (ou X% de desconto)
            'price_tiers' => ['sometimes', 'nullable', 'array', 'max:5'],
            'price_tiers.*.min_quantity' => ['required', 'integer', 'min:2', 'max:99999', 'distinct'],
            'price_tiers.*.type' => ['required', Rule::in(['fixed', 'percent'])],
            'price_tiers.*.value' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            // Faixa de atacado: só muda como o catálogo a apresenta ("Atacado a partir de N un"); o preço é o da faixa
            'price_tiers.*.wholesale' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            // Ativo mas fora do catálogo público — pra quem vende esse item só por fora
            // (marketplace, encomenda combinada) sem publicar no link da loja.
            'show_in_catalog' => ['sometimes', 'boolean'],

            'collection_ids' => ['sometimes', 'array'],
            'collection_ids.*' => ['integer'],

            // Grades de opções (acabamento, embalagem...): cada opção soma um valor ao preço (e ao custo) de cada unidade
            'option_groups' => ['sometimes', 'array', 'max:10'],
            'option_groups.*.id' => ['sometimes', 'nullable', 'integer'],
            'option_groups.*.name' => ['required', 'string', 'max:60'],
            'option_groups.*.type' => ['sometimes', Rule::in(ProductOptionGroup::TYPES)],
            'option_groups.*.required' => ['sometimes', 'boolean'],
            'option_groups.*.options' => ['required', 'array', 'min:1', 'max:30'],
            'option_groups.*.options.*.id' => ['sometimes', 'nullable', 'integer'],
            'option_groups.*.options.*.name' => ['required', 'string', 'max:60'],
            'option_groups.*.options.*.price_delta' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'option_groups.*.options.*.cost_delta' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'option_groups.*.options.*.active' => ['sometimes', 'boolean'],

            'variations' => ['sometimes', 'array', 'max:50'],
            'variations.*.id' => ['sometimes', 'nullable', 'integer'],
            // Ao menos um atributo precisa vir preenchido, senão a variação não
            // significa nada pra quem está comprando.
            'variations.*.color' => ['nullable', 'string', 'max:60', 'required_without_all:variations.*.size,variations.*.weight'],
            'variations.*.color_hexes' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_VARIATION_COLORS],
            'variations.*.color_hexes.*' => ['string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'variations.*.size' => ['nullable', 'string', 'max:60'],
            'variations.*.weight' => ['nullable', 'string', 'max:60'],
            'variations.*.sku' => ['nullable', 'string', 'max:255'],
            'variations.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'variations.*.cost' => ['nullable', 'numeric', 'min:0'],
            'variations.*.stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'variations.*.active' => ['sometimes', 'boolean'],
            // Tempo próprio (em branco = o do produto) e insumos que somam aos comuns (ex: o filamento da cor)
            'variations.*.print_time_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'variations.*.recipe' => ['sometimes', 'array', 'max:30'],
            'variations.*.recipe.*.material_id' => ['required', 'integer', Rule::exists('materials', 'id')->where('company_id', $companyId)],
            'variations.*.recipe.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ], [
            'sku.unique' => 'Este código já está em uso por outro produto.',
            'variations.*.color_hexes.*.regex' => 'Cor inválida na variação: use o formato #RRGGBB.',
            'variations.*.color_hexes.max' => 'Cada variação pode ter no máximo '.self::MAX_VARIATION_COLORS.' cores.',
            'option_groups.*.name.required' => 'Dê um nome para cada grade de opções (ex: Acabamento).',
            'option_groups.*.options.required' => 'Cada grade precisa de pelo menos uma opção.',
            'option_groups.*.options.min' => 'Cada grade precisa de pelo menos uma opção.',
            'option_groups.*.options.*.name.required' => 'Dê um nome para cada opção (ex: Fosco).',
            'pack_size.required_if' => 'Informe quantas unidades vêm no pacote.',
            'pack_size.min' => 'O pacote precisa ter pelo menos 2 unidades.',
            'variations.*.color.required_without_all' => 'Informe ao menos cor, tamanho ou peso na variação.',
        ]);

        if (array_key_exists('auto_cost', $data) && ! $data['auto_cost']) {
            $data['auto_price'] = false;
        }

        // Por unidade não tem tamanho nem nome de pacote (e não fica um valor velho guardado de quando era pacote)
        if (array_key_exists('sale_unit', $data) && $data['sale_unit'] === Product::UNIT) {
            $data['pack_size'] = null;
            $data['pack_label'] = null;
        }
        if (array_key_exists('pack_label', $data)) {
            $data['pack_label'] = trim((string) $data['pack_label']) ?: null;
        }

        if (array_key_exists('price_tiers', $data)) {
            $data['price_tiers'] = $this->normalizedTiers($data['price_tiers']);

            // Preço por quantidade é do plano Pro. Quem saiu do Pro pode editar o produto normalmente
            // (as faixas antigas ficam guardadas, só deixam de valer) e pode limpar as faixas, mas não criar nem alterar.
            if (! $request->user()->company->isPro()) {
                $unchanged = $data['price_tiers'] === ($current ? $this->normalizedTiers($current->price_tiers) : null);

                abort_unless(
                    $data['price_tiers'] === null || $unchanged,
                    403,
                    '"Preço por quantidade" é um recurso exclusivo do plano Pro. Assine para desbloquear.'
                );

                if ($unchanged) {
                    unset($data['price_tiers']);
                }
            }
        }

        return $data;
    }

    /** Faixas ordenadas pela quantidade, com os tipos numéricos certos; vazio vira null. */
    private function normalizedTiers(?array $tiers): ?array
    {
        $clean = collect($tiers ?? [])
            ->map(fn ($t) => [
                'min_quantity' => (int) $t['min_quantity'],
                'type' => $t['type'],
                'value' => $t['type'] === 'percent' ? min(95, round((float) $t['value'], 2)) : round((float) $t['value'], 2),
                'wholesale' => (bool) ($t['wholesale'] ?? false),
            ])
            ->sortBy('min_quantity')
            ->values()
            ->all();

        return $clean === [] ? null : $clean;
    }

    private function authorizeCompany(Request $request, Product $product): void
    {
        abort_unless($product->company_id === $request->user()->company_id, 403);
    }
}
