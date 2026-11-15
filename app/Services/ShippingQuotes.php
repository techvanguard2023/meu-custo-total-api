<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Cotação de frete da loja pelo Melhor Envio: monta os volumes a partir do peso e das medidas de cada produto
 * (ou do padrão da loja, quando o produto não tem), soma o prazo de preparo ao da transportadora e devolve as
 * opções da mais barata para a mais cara. Resultado fica 10 min em cache (mesmo CEP, mesmos itens).
 */
class ShippingQuotes
{
    public const DEFAULTS = ['enabled' => false, 'origin_cep' => null, 'weight_g' => 300, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10, 'handling_days' => 2];

    // Mínimos que as transportadoras aceitam (Correios): abaixo disso a cotação é recusada
    private const MIN_LENGTH = 16;

    private const MIN_WIDTH = 11;

    private const MIN_HEIGHT = 2;

    public function __construct(private MelhorEnvio $api, private MelhorEnvioAccounts $accounts) {}

    /** Regras da loja com os padrões aplicados. */
    public static function rules(Company $company): array
    {
        return array_merge(self::DEFAULTS, (array) ($company->setting?->shipping_rules ?? []));
    }

    public static function cleanCep(?string $cep): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $cep);

        return strlen($digits) === 8 ? $digits : null;
    }

    /** O frete está pronto para uso no catálogo: ligado, com CEP de origem e a conta conectada. */
    public function available(Company $company): bool
    {
        $rules = self::rules($company);

        return $company->hasEssential() && ! empty($rules['enabled']) && self::cleanCep($rules['origin_cep'] ?? null) !== null && $this->accounts->ready($company);
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return list<array{id: int, name: string, company: string, price: float, days: int, days_min: ?int, days_max: ?int}>
     *
     * @throws RuntimeException com frase para a tela
     */
    public function quote(Company $company, string $destinationCep, array $items): array
    {
        $rules = self::rules($company);
        $origin = self::cleanCep($rules['origin_cep'] ?? null);
        if (! $origin) {
            throw new RuntimeException('A loja ainda não informou o CEP de origem do envio.');
        }
        $to = self::cleanCep($destinationCep);
        if (! $to) {
            throw new RuntimeException('Informe um CEP com 8 números.');
        }

        $products = $this->volumes($company, $rules, $items);
        if ($products === []) {
            throw new RuntimeException('Nenhum produto do pedido pode ser enviado.');
        }

        $key = 'shipping-quote:'.$company->id.':'.md5(json_encode([$origin, $to, $products]));

        try {
            $options = Cache::remember($key, now()->addMinutes(10), fn () => $this->api->calculate($this->accounts->token($company), $origin, $to, $products));
        } catch (RuntimeException $e) {
            if ($e->getCode() === 401) {
                $this->accounts->revoke($company);
                throw new RuntimeException('A conta do Melhor Envio precisa ser conectada de novo.');
            }
            throw $e;
        }

        $handling = max(0, (int) $rules['handling_days']);
        $options = array_map(function (array $o) use ($handling) {
            $o['days'] += $handling;
            $o['days_min'] = $o['days_min'] !== null ? $o['days_min'] + $handling : null;
            $o['days_max'] = $o['days_max'] !== null ? $o['days_max'] + $handling : null;

            return $o;
        }, $options);
        usort($options, fn ($a, $b) => $a['price'] <=> $b['price']);

        return $options;
    }

    /** Um item de cotação por produto, com as medidas da embalagem e o valor declarado (preço de venda). */
    private function volumes(Company $company, array $rules, array $items): array
    {
        $quantities = [];
        foreach ($items as $item) {
            $id = (int) ($item['product_id'] ?? 0);
            $quantities[$id] = ($quantities[$id] ?? 0) + max(1, (int) ($item['quantity'] ?? 1));
        }
        if ($quantities === []) {
            return [];
        }

        $out = [];
        foreach (Product::where('company_id', $company->id)->whereIn('id', array_keys($quantities))->get() as $product) {
            $weightG = $product->ship_weight_g ?: (int) $rules['weight_g'];
            $price = (float) ($product->sale_price ?: $product->auto_price ?: 0);
            $out[] = [
                'id' => 'p'.$product->id,
                'width' => max(self::MIN_WIDTH, (int) ($product->ship_width_cm ?: $rules['width_cm'])),
                'height' => max(self::MIN_HEIGHT, (int) ($product->ship_height_cm ?: $rules['height_cm'])),
                'length' => max(self::MIN_LENGTH, (int) ($product->ship_length_cm ?: $rules['length_cm'])),
                'weight' => round(max(1, $weightG) / 1000, 3),
                'insurance_value' => round(max(0, $price), 2),
                'quantity' => $quantities[$product->id],
            ];
        }

        return $out;
    }
}
