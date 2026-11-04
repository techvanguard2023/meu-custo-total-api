<?php

namespace App\Models;

use App\Models\Concerns\BustsCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cupom de desconto da loja (Pro). Um por pedido; por padrão não vale para item com desconto (promoção ou
 * preço por quantidade), a não ser que o cupom permita. As regras de uso (validade, limites, primeira
 * compra) ficam em App\Services\Coupons; o valor do desconto, aqui.
 */
class Coupon extends Model
{
    use BustsCatalogCache;

    /** Catálogo público que mostra este registro (cache limpo ao gravar). */
    public function catalogCompanyId(): ?int
    {
        return $this->company_id;
    }

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'company_id', 'customer_id', 'automation', 'notified_at', 'notify_error', 'code', 'description', 'type', 'value', 'max_discount', 'min_order', 'starts_on', 'ends_on',
        'usage_limit', 'per_customer_limit', 'first_purchase_only', 'allow_promotions', 'share_in_offers', 'broadcast_at', 'category_ids', 'product_ids', 'active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'min_order' => 'decimal:2',
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
        'usage_limit' => 'integer',
        'per_customer_limit' => 'integer',
        'first_purchase_only' => 'boolean',
        'allow_promotions' => 'boolean',
        'category_ids' => 'array',
        'product_ids' => 'array',
        'active' => 'boolean',
        'notified_at' => 'datetime',
        'share_in_offers' => 'boolean',
        'broadcast_at' => 'datetime',
    ];

    public static function normalizeCode(?string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', (string) $code));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Dono do cupom pessoal (gerado pela automação) — só ele usa. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /** Séries de ofertas (grupos/canal) que divulgam este cupom. */
    public function offerSeries(): HasMany
    {
        return $this->hasMany(OfferSeries::class);
    }

    /** Pedidos que contam como uso: orçamentos em aberto e vendas (recusado e cancelado devolvem o uso). */
    public function usedQuotes(): HasMany
    {
        return $this->quotes()->whereNotIn('status', [Quote::STATUS_REJECTED, Quote::STATUS_CANCELLED]);
    }

    /** "10%" ou "R$ 15,00" — como o desconto aparece pro cliente. */
    public function label(): string
    {
        return $this->type === self::TYPE_PERCENT
            ? rtrim(rtrim(number_format((float) $this->value, 2, ',', ''), '0'), ',').'%'
            : 'R$ '.number_format((float) $this->value, 2, ',', '.');
    }

    private function restricted(): bool
    {
        return ! empty($this->category_ids) || ! empty($this->product_ids);
    }

    /** Produto dentro das categorias/produtos do cupom (categoria vale com as subcategorias). */
    public function coversProduct(Product $product): bool
    {
        if (! $this->restricted()) {
            return true;
        }

        $categories = array_map('intval', $this->category_ids ?? []);

        return in_array($product->id, array_map('intval', $this->product_ids ?? []), true)
            || ($product->category_id && in_array((int) $product->category_id, $categories, true))
            || ($product->category?->parent_id && in_array((int) $product->category->parent_id, $categories, true));
    }

    /**
     * Desconto do cupom neste pedido e, quando não dá desconto, o motivo pra mostrar.
     *
     * @param  array<int, array{product: Product, line_total: float, discounted: bool}>  $lines  produtos do pedido
     * @param  float  $otherAmount  parte que não é produto pronto (impressão sob medida) — entra só em cupom sem restrição de itens
     * @return array{0: float, 1: string|null}
     */
    public function discountFor(array $lines, float $otherAmount = 0.0): array
    {
        $subtotal = $otherAmount + array_sum(array_column($lines, 'line_total'));
        if ($this->min_order !== null && $subtotal + 0.001 < (float) $this->min_order) {
            return [0.0, 'Este cupom vale para pedidos a partir de R$ '.number_format((float) $this->min_order, 2, ',', '.').'.'];
        }

        $base = $this->restricted() ? 0.0 : $otherAmount;
        $hasCovered = false;
        foreach ($lines as $line) {
            if (! $this->coversProduct($line['product'])) {
                continue;
            }
            $hasCovered = true;
            if ($line['discounted'] && ! $this->allow_promotions) {
                continue;
            }
            $base += $line['line_total'];
        }

        if ($base <= 0) {
            return [0.0, $hasCovered || ! $this->restricted()
                ? 'O cupom não vale para itens em promoção.'
                : 'O cupom não vale para os itens deste pedido.'];
        }

        $amount = $this->type === self::TYPE_PERCENT
            ? $base * min(100, (float) $this->value) / 100
            : (float) $this->value;
        if ($this->max_discount !== null) {
            $amount = min($amount, (float) $this->max_discount);
        }

        return [round(min($amount, $base), 2), null];
    }
}
