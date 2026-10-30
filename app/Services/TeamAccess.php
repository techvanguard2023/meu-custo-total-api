<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * O que a equipe pode fazer. O dono pode tudo; para vendas e produção vale a primeira regra que bater
 * com a rota (sem regra = só o dono). Os mesmos perfis estão em src/lib/permissions.ts no frontend.
 */
class TeamAccess
{
    private const ALL = [User::ROLE_SALES, User::ROLE_PRODUCTION];

    private const SALES = [User::ROLE_SALES];

    /** [método ou *, rota sem o prefixo api/v1/ (com * no fim = e tudo abaixo), perfis] */
    private const RULES = [
        ['*', 'me', self::ALL],
        ['*', 'logout', self::ALL],
        ['POST', 'account/accept-terms', self::ALL],
        ['*', 'profile', self::ALL],
        ['*', 'profile/password', self::ALL],
        ['GET', 'plan', self::ALL],
        ['GET', 'settings', self::ALL],
        ['GET', 'catalog', self::ALL],
        // Status do WhatsApp: as telas decidem se o aviso ao cliente sai automático (o QR fica de fora)
        ['GET', 'whatsapp', self::ALL],

        // Linha de produção (vendas também acompanha e move os pedidos)
        ['GET', 'quotes', self::ALL],
        ['GET', 'quotes/{quote}', self::ALL],
        ['PATCH', 'quotes/{quote}/production-status', self::ALL],
        ['PATCH', 'quotes/production-order', self::ALL],
        ['PATCH', 'quotes/{quote}/pause', self::ALL],
        ['PATCH', 'quotes/{quote}/resume', self::ALL],

        // Produtos e insumos: todos consultam; só o dono cadastra e altera
        ['GET', 'products', self::ALL],
        ['GET', 'products/{product}', self::ALL],
        ['POST', 'products/{product}/production', [User::ROLE_PRODUCTION]],
        ['GET', 'product-categories', self::ALL],
        ['GET', 'product-collections', self::ALL],
        ['GET', 'materials', self::ALL],
        ['GET', 'materials/{material}', self::ALL],
        ['GET', 'materials/{material}/movements', self::ALL],
        ['GET', 'material-categories', self::ALL],

        // Vendas: orçamentos (exceto excluir), clientes, caixa, avaliações, expositores, conversas pausadas
        ['GET', 'printers', self::SALES],
        ['GET', 'printers/{printer}', self::SALES],
        ['GET', 'sales-channels', self::SALES],
        ['DELETE', 'quotes/{quote}', []],
        ['*', 'quotes*', self::SALES],
        ['*', 'quote-requests*', self::SALES],
        ['*', 'customers*', self::SALES],
        ['*', 'product-reviews*', self::SALES],
        ['*', 'displays*', self::SALES],
        ['*', 'whatsapp/handoffs*', self::SALES],
    ];

    /** Custos, lucro e parâmetros de custo — saem das respostas para quem não é dono. */
    private const COST_KEYS = [
        'cost', 'unit_cost', 'line_cost', 'material_cost', 'energy_cost', 'depreciation_cost', 'labor_cost',
        'extra_costs', 'subtotal_cost', 'failure_cost', 'failure_rate_percent', 'print_total_cost', 'products_cost',
        'total_cost', 'markup_percent', 'profit_amount', 'channel_fee_amount',
        'cost_per_g', 'spool_cost', 'purchase_price', 'maintenance_percent', 'electricity_rate_kwh',
        'labor_hour_rate', 'default_failure_rate', 'default_markup', 'suggested_price', 'suggested_profit',
    ];

    /** Só do dono, qualquer que seja o perfil: o QR Code que conecta o WhatsApp da loja. */
    private const OWNER_ONLY_KEYS = ['qr'];

    /** Valores de venda — saem também para a produção, que trabalha sem valores. */
    private const PRICE_KEYS = [
        'price', 'final_price', 'unit_price', 'regular_unit_price', 'sale_price', 'original_price', 'amount',
        'amount_paid', 'line_total', 'discount_amount', 'cashback_amount', 'cashback_max', 'products_total',
        'subtotal', 'minimum_order_price', 'order_discount_amount', 'order_discount_tiers', 'price_tiers',
        'amount_due', 'balance', 'list_price',
    ];

    public static function allows(User $user, string $method, string $uri): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        $uri = Str::after($uri, 'api/v1/');

        foreach (self::RULES as [$ruleMethod, $pattern, $roles]) {
            if (($ruleMethod === '*' || $ruleMethod === $method) && Str::is($pattern, $uri)) {
                return in_array($user->role, $roles, true);
            }
        }

        return false;
    }

    /** Tira da resposta o que o perfil não pode ver (custos; e preços, na produção). */
    public static function filter(mixed $data, User $user): mixed
    {
        if ($user->seesCosts()) {
            return $data;
        }

        if (! $user->seesPrices()) {
            return self::strip($data, array_flip([...self::COST_KEYS, ...self::PRICE_KEYS, ...self::OWNER_ONLY_KEYS]), false);
        }

        // Vendas não recebe custo nem margem, mas precisa do preço de produto "automático" (custo + margem)
        $markup = (float) ($user->company?->setting?->default_markup ?? 0);

        return self::strip(self::withListPrices($data, $markup), array_flip([...self::COST_KEYS, ...self::OWNER_ONLY_KEYS]), false);
    }

    /**
     * Acrescenta `list_price` em produtos e variações: o preço de tabela já resolvido (o preço digitado ou,
     * em branco, custo + margem padrão — a mesma regra do catálogo e do Caixa).
     */
    private static function withListPrices(mixed $data, float $markup): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $auto = fn ($cost) => $cost === null ? null : round((float) $cost * (1 + $markup / 100), 2);

        if (array_key_exists('cost', $data) && array_key_exists('sale_price', $data) && array_key_exists('variations', $data)) {
            $data['list_price'] = $data['sale_price'] !== null ? (float) $data['sale_price'] : $auto($data['cost']);

            if (is_array($data['variations'])) {
                foreach ($data['variations'] as $i => $variation) {
                    if (is_array($variation) && array_key_exists('sale_price', $variation)) {
                        $data['variations'][$i]['list_price'] = $variation['sale_price'] !== null
                            ? (float) $variation['sale_price']
                            : ($data['sale_price'] !== null ? (float) $data['sale_price'] : $auto($variation['cost'] ?? $data['cost']));
                    }
                }
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withListPrices($value, $markup);
            }
        }

        return $data;
    }

    private static function strip(mixed $data, array $hidden, bool $inMaterialLines): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            // Linhas de insumo do cálculo: o "total da linha" ali é custo, não preço
            if (is_string($key) && (isset($hidden[$key]) || ($inMaterialLines && $key === 'line_total'))) {
                continue;
            }
            $out[$key] = self::strip($value, $hidden, $key === 'materials' || ($inMaterialLines && is_int($key)));
        }

        return $out;
    }
}
