<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Importar planilha (produtos, insumos e clientes). A tela lê o arquivo no navegador, liga as colunas aos campos e
 * manda as linhas já como {campo: texto}. Aqui cada linha vira: create (novo), update (já existia e a loja escolheu
 * atualizar), skip (já existia, ou nada mudou), error (com os motivos) ou limit (passou do limite do plano Grátis).
 *
 * A simulação (dry run) roda exatamente o mesmo código dentro de uma transação desfeita no fim — o resultado que a
 * tela mostra antes de importar é o mesmo que a importação vai dar.
 *
 * Regras de "já existe":
 *  - produto: pelo código (SKU); sem código, pelo nome (entre os que não têm código diferente);
 *  - insumo: nome + marca + cor;
 *  - cliente: telefone (mesma comparação do resto do sistema); sem telefone, e-mail; sem nenhum dos dois, nome.
 * Ao atualizar, só os campos preenchidos na planilha mudam (vazio = deixa como está).
 */
class DataImport
{
    public const MAX_ROWS = 2000;

    public const TYPES = ['products', 'materials', 'customers'];

    public const MODES = ['skip', 'update'];

    /** Insumo em gramas sem categoria cai nesta (criada para a loja na primeira vez). */
    private const WEIGHT_FALLBACK_CATEGORY = 'Outros (por peso)';

    private const UNIT_FALLBACK_CATEGORY = 'Outro';

    private Company $company;

    private string $mode;

    /** Quantos ainda cabem no plano Grátis (null = sem limite). */
    private ?int $remaining = null;

    private array $out = [];

    /** Slugs em uso na importação de produtos (referência à lista de products()). */
    private array $slugs = [];

    /** Fotos a baixar depois de gravar: [{product_id, line, label, urls}] (ver ProductPhotoImport). */
    private array $photos = [];

    /** @param  array<int, array<string, mixed>>  $rows */
    public function run(Company $company, string $type, array $rows, string $mode, bool $dryRun): array
    {
        $this->company = $company;
        $this->mode = $mode;
        $this->out = [];
        $this->photos = [];

        // Uma limpeza do cache do catálogo no fim, em vez de uma por produto gravado
        CatalogCache::batch(function () use ($type, $rows, $dryRun) {
            DB::beginTransaction();
            try {
                match ($type) {
                    'products' => $this->products($rows),
                    'materials' => $this->materials($rows),
                    'customers' => $this->customers($rows),
                };
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
            $dryRun ? DB::rollBack() : DB::commit();
        });

        // Fotos por link: os produtos já estão gravados; as fotos são baixadas em segundo plano (a tela acompanha o lote)
        $photoBatch = ! $dryRun && $this->photos ? app(ProductPhotoImport::class)->start($company->id, $this->photos) : null;

        $counts = array_fill_keys(['create', 'update', 'skip', 'error', 'limit'], 0);
        foreach ($this->out as $row) {
            $counts[$row['status']]++;
        }

        return [
            'dry_run' => $dryRun,
            'counts' => $counts,
            'limit' => $company->hasEssential() ? null : (Company::FREE_LIMITS[$type] ?? null),
            'photos' => array_sum(array_map(fn ($p) => count($p['urls']), $this->photos)),
            'photo_batch' => $photoBatch,
            'rows' => $this->out,
        ];
    }

    /* ------------------------------------------------------------------ produtos */

    private function products(array $rows): void
    {
        $company = $this->company;
        $this->remaining = $company->hasEssential() ? null : max(0, Company::FREE_LIMITS['products'] - $company->products()->count());

        $existing = $company->products()->withCount(['variations', 'images'])->get();
        $photosAllowed = $company->hasEssential();
        $bySku = $existing->filter(fn ($p) => $p->sku !== null && $p->sku !== '')->keyBy(fn ($p) => $this->key($p->sku));
        $byName = $existing->groupBy(fn ($p) => $this->key($p->name));
        $categories = ProductCategory::listedFor($company)->get();
        // Slugs em uso (produtos da loja + categorias visíveis a ela), mesma regra de Product::uniqueSlug
        $slugs = array_fill_keys(array_merge(
            $existing->pluck('slug')->filter()->all(),
            ProductCategory::visibleTo($company->id)->pluck('slug')->filter()->all(),
        ), true);
        $this->slugs = &$slugs;
        $seen = [];

        foreach ($rows as $i => $row) {
            [$line, $errors, $notes] = [$this->line($row, $i), [], []];

            $name = $this->text($row, 'name', 255);
            $sku = $this->text($row, 'sku', 255);
            $description = $this->text($row, 'description', 10000, keepLines: true);
            $categoryText = $this->text($row, 'category', 130);
            $price = $this->decimal($row, 'sale_price', 'Preço de venda', $errors, max: 99999999);
            $cost = $this->decimal($row, 'cost', 'Custo', $errors, max: 99999999);
            $stock = $this->integer($row, 'stock_quantity', 'Estoque', $errors, 0, 9999999);
            $madeToOrder = $this->bool($row, 'made_to_order', 'Sob encomenda', $errors);
            $lead = $this->integer($row, 'lead_time_days', 'Prazo de produção', $errors, 1, 365);
            $active = $this->bool($row, 'active', 'Ativo', $errors);
            $inCatalog = $this->bool($row, 'show_in_catalog', 'Mostrar no catálogo', $errors);
            $photoUrls = $this->photoLinks($row, $photosAllowed, $notes);

            $key = $sku !== null ? 'sku:'.$this->key($sku) : ($name !== null ? 'name:'.$this->key($name) : null);
            if ($key !== null && isset($seen[$key])) {
                $errors[] = "Repetido na planilha (igual à linha {$seen[$key]}).";
            } elseif ($key !== null) {
                $seen[$key] = $line;
            }

            $match = $sku !== null ? $bySku->get($this->key($sku)) : null;
            if (! $match && $name !== null) {
                // Pelo nome, só entre os que não têm outro código — "Vaso" com código A e "Vaso" com código B são dois produtos
                $match = ($byName->get($this->key($name)) ?? collect())
                    ->first(fn ($p) => $p->sku === null || $p->sku === '' || ($sku !== null && $this->key($p->sku) === $this->key($sku)));
            }

            if (! $match && $name === null) {
                $errors[] = 'Falta o nome do produto.';
            }
            if ($match && $sku !== null && $match->sku && $this->key($match->sku) !== $this->key($sku)) {
                $errors[] = "O código {$sku} já é de outro produto.";
            }

            $label = $name ?? $match?->name ?? $sku ?? '—';
            if ($errors) {
                $this->push($line, 'error', $label, $errors);
                continue;
            }

            if ($match) {
                if ($this->mode === 'skip') {
                    $this->push($line, 'skip', $label, ['Já cadastrado.']);
                    continue;
                }

                $changes = array_filter([
                    'name' => $name,
                    'sku' => $sku,
                    'description' => $description,
                    'made_to_order' => $madeToOrder,
                    'lead_time_days' => $lead,
                    'active' => $active,
                    'show_in_catalog' => $inCatalog,
                ], fn ($v) => $v !== null);
                if ($cost !== null && $match->auto_cost) {
                    $notes[] = 'O custo vem da ficha técnica: mantido.';
                } elseif ($cost !== null) {
                    $changes['cost'] = $cost;
                }
                if ($price !== null && $match->auto_price) {
                    $notes[] = 'O preço vem da ficha técnica: mantido.';
                } elseif ($price !== null) {
                    $changes['sale_price'] = $price;
                }
                if ($stock !== null && $match->variations_count > 0) {
                    $notes[] = 'Tem variações: o estoque fica em cada variação.';
                } elseif ($stock !== null) {
                    $changes['stock_quantity'] = $stock;
                }
                if ($lead !== null && ! $match->lead_time_days_type) {
                    $changes['lead_time_days_type'] = 'business';
                }
                if ($categoryText !== null) {
                    $changes['category_id'] = $this->productCategory($categoryText, $categories, $notes);
                }
                // Quem já tem fotos fica com as dele (reimportar a mesma planilha não duplica a galeria)
                if ($photoUrls && $match->images_count > 0) {
                    $notes[] = 'Já tem fotos: as da planilha não foram baixadas.';
                    $photoUrls = [];
                }

                $match->fill($changes);
                if (! $match->isDirty() && ! $photoUrls) {
                    $this->push($line, 'skip', $label, array_merge(['Nada mudou.'], $notes));
                    continue;
                }
                $match->save();
                $this->queuePhotos($match->id, $line, $label, $photoUrls);
                $this->push($line, 'update', $label, $notes);
                continue;
            }

            if (! $this->takeSlot()) {
                $this->push($line, 'limit', $label, ['Passou do limite do plano Grátis.']);
                continue;
            }

            $product = $company->products()->create([
                'name' => $name,
                'slug' => $slug = Product::uniqueSlug($name, $company->id, fn ($s) => isset($slugs[$s])),
                'sku' => $sku,
                'description' => $description,
                'category_id' => $categoryText !== null ? $this->productCategory($categoryText, $categories, $notes) : null,
                'sale_price' => $price,
                // Custo informado à mão (a ficha técnica pode ser montada depois, no cadastro do produto)
                'cost' => $cost ?? 0,
                'auto_cost' => false,
                'auto_price' => false,
                'stock_quantity' => $stock ?? 0,
                'made_to_order' => $madeToOrder ?? false,
                'lead_time_days' => $lead,
                'lead_time_days_type' => $lead ? 'business' : null,
                'active' => $active ?? true,
                'show_in_catalog' => $inCatalog ?? true,
            ]);
            $slugs[$slug] = true;
            if ($cost === null) {
                $notes[] = 'Sem custo: preencha depois para o lucro sair certo.';
            }
            if ($sku !== null) {
                $bySku->put($this->key($sku), $product);
            }
            $this->queuePhotos($product->id, $line, $label, $photoUrls);
            $this->push($line, 'create', $label, $notes);
        }
    }

    /** Links da coluna de fotos (inválidos viram aviso). Fotos são dos planos Essencial e Pro, como no cadastro. */
    private function photoLinks(array $row, bool $allowed, array &$notes): array
    {
        $raw = $this->text($row, 'photos', 20000, keepLines: true);
        if ($raw === null) {
            return [];
        }
        $links = app(ProductPhotoImport::class)->parseLinks($raw);
        foreach ($links['invalid'] as $bad) {
            $notes[] = "Link de foto ignorado (não começa com http): {$bad}.";
        }
        if ($links['urls'] && ! $allowed) {
            $notes[] = 'Fotos são dos planos Essencial e Pro: não foram baixadas.';

            return [];
        }
        if (count($links['urls']) > ProductPhotoImport::MAX_PER_PRODUCT) {
            $notes[] = 'Mais de '.ProductPhotoImport::MAX_PER_PRODUCT.' fotos: só as '.ProductPhotoImport::MAX_PER_PRODUCT.' primeiras.';
        }

        return array_slice($links['urls'], 0, ProductPhotoImport::MAX_PER_PRODUCT);
    }

    /** O total de fotos vai no resumo ("photos"), não em cada linha — com centenas de produtos a lista ficaria só disso. */
    private function queuePhotos(int $productId, int $line, string $label, array $urls): void
    {
        if ($urls) {
            $this->photos[] = ['product_id' => $productId, 'line' => $line, 'label' => mb_substr($label, 0, 120), 'urls' => $urls];
        }
    }

    /** "Chaveiros" ou "Chaveiros > Carros" — usa a que existe (mesmo nome, sem diferenciar maiúsculas) ou cria. */
    private function productCategory(string $text, Collection $categories, array &$notes): int
    {
        $parts = array_values(array_filter(array_map(fn ($p) => mb_substr(trim($p), 0, 60), explode('>', $text)), fn ($p) => $p !== ''));
        $parentId = null;
        $category = null;

        foreach (array_slice($parts, 0, 2) as $part) {
            $category = $categories->first(fn ($c) => $c->parent_id === $parentId && $this->key($c->name) === $this->key($part));
            if (! $category) {
                $category = ProductCategory::create([
                    'company_id' => $this->company->id,
                    'parent_id' => $parentId,
                    'name' => $part,
                    'slug' => ProductCategory::uniqueSlug($part, $this->company->id, $parentId),
                ]);
                $this->slugs[$category->slug] = true;
                $categories->push($category);
                $notes[] = "Categoria nova: {$part}.";
            }
            $parentId = $category->id;
        }

        return $category->id;
    }

    /* ------------------------------------------------------------------ insumos */

    private function materials(array $rows): void
    {
        $company = $this->company;
        $existing = $company->materials()->with('category')->get();
        $byKey = $existing->keyBy(fn ($m) => $this->materialKey($m->name, $m->brand, $m->color));
        $categories = MaterialCategory::listedFor($company)->get();
        $seen = [];

        foreach ($rows as $i => $row) {
            [$line, $errors, $notes] = [$this->line($row, $i), [], []];

            $name = $this->text($row, 'name', 255);
            $brand = $this->text($row, 'brand', 255);
            $color = $this->text($row, 'color', 255);
            $categoryText = $this->text($row, 'category', 60);
            $unit = $this->unit($row, $errors);
            $qty = $this->decimal($row, 'package_quantity', 'Quantidade na embalagem', $errors, max: 99999999);
            $price = $this->decimal($row, 'package_price', 'Preço da embalagem', $errors, max: 99999999);
            $stock = $this->decimal($row, 'stock_quantity', 'Estoque', $errors, max: 9999999999);

            if ($name === null) {
                $errors[] = 'Falta o nome.';
            }
            $key = $name !== null ? $this->materialKey($name, $brand, $color) : null;
            if ($key !== null && isset($seen[$key])) {
                $errors[] = "Repetido na planilha (igual à linha {$seen[$key]}).";
            } elseif ($key !== null) {
                $seen[$key] = $line;
            }
            $match = $key !== null ? $byKey->get($key) : null;

            // Categoria e unidade: a categoria que já existe manda (gramas ou unidade); "kg" só converte para gramas
            $category = null;
            if ($categoryText !== null) {
                $category = $categories->first(fn ($c) => $this->key($c->name) === $this->key($categoryText));
            } elseif ($match) {
                $category = $match->category;
            }
            $byWeight = $category ? $category->pricing_unit === MaterialCategory::PRICING_WEIGHT : in_array($unit, ['g', 'kg'], true);
            if ($category && $unit === 'un' && $byWeight) {
                $errors[] = "A categoria {$category->name} é medida em gramas (use g ou kg).";
            }
            if ($category && in_array($unit, ['g', 'kg'], true) && ! $byWeight) {
                $errors[] = "A categoria {$category->name} é medida em unidades (use un).";
            }
            $factor = $unit === 'kg' ? 1000 : 1;
            $qty = $qty !== null ? $qty * $factor : null;
            $stock = $stock !== null ? $stock * $factor : null;

            if ($qty !== null && round($qty) < 1) {
                $errors[] = 'A quantidade na embalagem precisa ser de pelo menos 1 '.($byWeight ? 'g' : 'unidade').'.';
            }
            if (! $match && $qty === null) {
                $errors[] = 'Falta a quantidade na embalagem.';
            }
            if (! $match && $price === null) {
                $errors[] = 'Falta o preço da embalagem.';
            }

            $label = trim(implode(' ', array_filter([$name, $brand, $color]))) ?: '—';
            if ($errors) {
                $this->push($line, 'error', $label, $errors);
                continue;
            }
            if ($match && $this->mode === 'skip') {
                $this->push($line, 'skip', $label, ['Já cadastrado.']);
                continue;
            }

            if (! $category && ($categoryText !== null || ! $match)) {
                $category = $this->materialCategory($categoryText, $byWeight, $categories, $notes);
            }

            if ($match) {
                $before = $match->stock_quantity;
                $spoolWeight = $qty !== null ? (int) round($qty) : (int) $match->spool_weight_g;
                $spoolCost = $price ?? (float) $match->spool_cost;
                $match->fill(array_filter([
                    'material_category_id' => $category?->id,
                    'spool_weight_g' => $qty !== null ? $spoolWeight : null,
                    'spool_cost' => $price,
                    'cost_per_g' => ($qty !== null || $price !== null) ? round($spoolCost / max(1, $spoolWeight), 4) : null,
                    'stock_quantity' => $stock !== null ? round($stock, 2) : null,
                ], fn ($v) => $v !== null));
                if (! $match->isDirty()) {
                    $this->push($line, 'skip', $label, ['Nada mudou.']);
                    continue;
                }
                $match->save();
                if ($stock !== null && round($stock, 2) != (float) ($before ?? 0)) {
                    MaterialStock::record($match, round($stock, 2) - (float) ($before ?? 0), MaterialMovement::TYPE_ADJUSTMENT, ['note' => 'Ajuste pela planilha']);
                }
                $this->push($line, 'update', $label, $notes);
                continue;
            }

            $spoolWeight = (int) round($qty);
            $material = $company->materials()->create([
                'name' => $name,
                'brand' => $brand,
                'color' => $color,
                'material_category_id' => $category?->id,
                'spool_weight_g' => $spoolWeight,
                'spool_cost' => $price,
                'cost_per_g' => round($price / $spoolWeight, 4),
                'stock_quantity' => $stock !== null ? round($stock, 2) : null,
                'active' => true,
            ]);
            if ($stock !== null && $stock != 0) {
                MaterialStock::record($material, round($stock, 2), MaterialMovement::TYPE_ADJUSTMENT, ['note' => 'Estoque inicial (planilha)']);
            }
            $byKey->put($key, $material);
            $this->push($line, 'create', $label, $notes);
        }
    }

    /** Categoria pelo nome; sem nome, a padrão "Outro" (unidade) ou "Outros (por peso)". Cria quando não existe. */
    private function materialCategory(?string $name, bool $byWeight, Collection $categories, array &$notes): MaterialCategory
    {
        $unit = $byWeight ? MaterialCategory::PRICING_WEIGHT : MaterialCategory::PRICING_UNIT;
        $name ??= $byWeight ? self::WEIGHT_FALLBACK_CATEGORY : self::UNIT_FALLBACK_CATEGORY;

        $found = $categories->first(fn ($c) => $this->key($c->name) === $this->key($name) && $c->pricing_unit === $unit);
        if ($found) {
            return $found;
        }

        $category = MaterialCategory::create(['company_id' => $this->company->id, 'name' => $name, 'pricing_unit' => $unit]);
        $categories->push($category);
        $notes[] = "Categoria nova: {$name} (".($byWeight ? 'em gramas' : 'por unidade').').';

        return $category;
    }

    private function materialKey(?string $name, ?string $brand, ?string $color): string
    {
        return $this->key($name).'|'.$this->key($brand).'|'.$this->key($color);
    }

    /** g, kg ou un (vazio = segue a categoria). */
    private function unit(array $row, array &$errors): ?string
    {
        $raw = $this->text($row, 'unit', 30);
        if ($raw === null) {
            return null;
        }
        $v = $this->key(rtrim($raw, '.'));

        return match (true) {
            in_array($v, ['g', 'gr', 'grs', 'grama', 'gramas'], true) => 'g',
            in_array($v, ['kg', 'kgs', 'quilo', 'quilos', 'kilo', 'kilos'], true) => 'kg',
            in_array($v, ['un', 'und', 'unid', 'unidade', 'unidades', 'u', 'pc', 'pç', 'peça', 'peças', 'pct', 'pacote'], true) => 'un',
            default => (function () use (&$errors, $raw) {
                $errors[] = "Unidade \"{$raw}\" não reconhecida (use g, kg ou un).";

                return null;
            })(),
        };
    }

    /* ------------------------------------------------------------------ clientes */

    private function customers(array $rows): void
    {
        $company = $this->company;
        $this->remaining = $company->hasEssential() ? null : max(0, Company::FREE_LIMITS['customers'] - $company->customers()->count());

        $existing = $company->customers()->get();
        $byPhone = $existing->filter(fn ($c) => Customer::phoneKey($c->phone))->keyBy(fn ($c) => Customer::phoneKey($c->phone));
        $byEmail = $existing->filter(fn ($c) => $c->email)->keyBy(fn ($c) => $this->key($c->email));
        $byName = $existing->filter(fn ($c) => ! Customer::phoneKey($c->phone) && ! $c->email)->keyBy(fn ($c) => $this->key($c->name));
        $seen = [];

        foreach ($rows as $i => $row) {
            [$line, $errors] = [$this->line($row, $i), []];

            $name = $this->text($row, 'name', 255);
            $phone = $this->text($row, 'phone', 40);
            $email = $this->text($row, 'email', 255);
            $notes = $this->text($row, 'notes', 5000, keepLines: true);

            if ($name === null) {
                $errors[] = 'Falta o nome.';
            }
            $phoneKey = Customer::phoneKey($phone);
            if ($phone !== null && strlen(preg_replace('/\D/', '', $phone)) < 8) {
                $errors[] = "Telefone inválido: {$phone}.";
                $phoneKey = null;
            }
            if ($email !== null) {
                $email = mb_strtolower($email);
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "E-mail inválido: {$email}.";
                }
            }

            $key = $phoneKey ? 'p:'.$phoneKey : ($email ? 'e:'.$email : ($name ? 'n:'.$this->key($name) : null));
            if ($key !== null && isset($seen[$key])) {
                $errors[] = "Repetido na planilha (igual à linha {$seen[$key]}).";
            } elseif ($key !== null) {
                $seen[$key] = $line;
            }

            $match = $phoneKey ? $byPhone->get($phoneKey) : null;
            $match ??= $email ? $byEmail->get($email) : null;
            $match ??= (! $phoneKey && ! $email && $name) ? $byName->get($this->key($name)) : null;

            $label = $name ?? $match?->name ?? $phone ?? '—';
            if ($errors) {
                $this->push($line, 'error', $label, $errors);
                continue;
            }

            if ($match) {
                if ($this->mode === 'skip') {
                    $this->push($line, 'skip', $label, ['Já cadastrado.']);
                    continue;
                }
                $match->fill(array_filter(['name' => $name, 'phone' => $phone, 'email' => $email, 'notes' => $notes], fn ($v) => $v !== null));
                if (! $match->isDirty()) {
                    $this->push($line, 'skip', $label, ['Nada mudou.']);
                    continue;
                }
                $match->save();
                $this->push($line, 'update', $label, []);
                continue;
            }

            if (! $this->takeSlot()) {
                $this->push($line, 'limit', $label, ['Passou do limite do plano Grátis.']);
                continue;
            }

            $customer = $company->customers()->create(['name' => $name, 'phone' => $phone, 'email' => $email, 'notes' => $notes]);
            if ($phoneKey) {
                $byPhone->put($phoneKey, $customer);
            }
            if ($email) {
                $byEmail->put($email, $customer);
            }
            $this->push($line, 'create', $label, []);
        }
    }

    /* ------------------------------------------------------------------ leitura dos valores */

    private function push(int $line, string $status, string $label, array $messages): void
    {
        $this->out[] = ['line' => $line, 'status' => $status, 'label' => mb_substr($label, 0, 120), 'messages' => array_values($messages)];
    }

    private function takeSlot(): bool
    {
        if ($this->remaining === null) {
            return true;
        }
        if ($this->remaining <= 0) {
            return false;
        }
        $this->remaining--;

        return true;
    }

    /** Linha da planilha (a tela manda; sem ela, conta a partir da 2, depois do cabeçalho). */
    private function line(array $row, int $index): int
    {
        $line = $row['_line'] ?? null;

        return is_numeric($line) ? (int) $line : $index + 2;
    }

    private function key(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)));
    }

    /** Texto aparado (vazio = null). Nomes perdem espaços repetidos; descrições mantêm as quebras de linha. */
    private function text(array $row, string $field, int $max, bool $keepLines = false): ?string
    {
        $value = $row[$field] ?? null;
        if (! is_scalar($value)) {
            return null;
        }
        $value = $keepLines ? trim(str_replace("\r\n", "\n", (string) $value)) : trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * Número no jeito brasileiro ou no americano: "R$ 1.234,56", "12,5", "12.5", "1,234.56". "1.500" (ponto com
     * três casas e nada mais) é mil e quinhentos, como no Excel em português. Os números das células do .xlsx a
     * tela já manda com vírgula decimal, então não caem nessa dúvida.
     */
    private function decimal(array $row, string $field, string $label, array &$errors, float $max): ?float
    {
        $raw = $this->text($row, $field, 60);
        if ($raw === null) {
            return null;
        }
        $s = preg_replace('/[^\d,.\-]/', '', $raw);
        $comma = strrpos($s, ',');
        $dot = strrpos($s, '.');
        if ($comma !== false && $dot !== false) {
            $s = $comma > $dot ? str_replace(',', '.', str_replace('.', '', $s)) : str_replace(',', '', $s);
        } elseif ($comma !== false) {
            $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($dot !== false && (substr_count($s, '.') > 1 || preg_match('/^-?\d{1,3}\.\d{3}$/', $s))) {
            $s = str_replace('.', '', $s);
        }

        if (! is_numeric($s)) {
            $errors[] = "{$label} inválido: {$raw}.";

            return null;
        }
        $value = (float) $s;
        if ($value < 0 || $value > $max) {
            $errors[] = "{$label} fora do permitido: {$raw}.";

            return null;
        }

        return $value;
    }

    private function integer(array $row, string $field, string $label, array &$errors, int $min, int $max): ?int
    {
        $before = count($errors);
        $value = $this->decimal($row, $field, $label, $errors, PHP_INT_MAX);
        if ($value === null || count($errors) > $before) {
            return null;
        }
        if (floor($value) != $value || $value < $min || $value > $max) {
            $errors[] = "{$label} precisa ser um número inteiro entre {$min} e {$max}.";

            return null;
        }

        return (int) $value;
    }

    private function bool(array $row, string $field, string $label, array &$errors): ?bool
    {
        $raw = $this->text($row, $field, 20);
        if ($raw === null) {
            return null;
        }
        $v = $this->key(rtrim($raw, '.'));
        if (in_array($v, ['sim', 's', 'yes', 'y', 'true', 'verdadeiro', '1', 'x', 'ativo', 'ativado'], true)) {
            return true;
        }
        if (in_array($v, ['não', 'nao', 'n', 'no', 'false', 'falso', '0', 'inativo', 'desativado'], true)) {
            return false;
        }
        $errors[] = "{$label}: use sim ou não (veio \"{$raw}\").";

        return null;
    }
}
