<?php

namespace App\Models;

use App\Models\Concerns\BustsCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use BustsCatalogCache;

    /** Catálogo público que mostra este registro (cache limpo ao gravar). */
    public function catalogCompanyId(): ?int
    {
        return $this->company_id;
    }

    protected $fillable = [
        'company_id', 'electricity_rate_kwh', 'labor_hour_rate',
        'default_failure_rate', 'default_markup', 'minimum_order_price', 'order_discount_tiers',
        'cashback_enabled', 'cashback_purchase_percent', 'cashback_review_percent', 'cashback_referral_percent', 'cashback_max_redeem_percent', 'cashback_validity_months',
        'delivery_rules', 'payment_rules',
        'employees', 'fixed_expenses', 'fixed_costs_mode', 'estimated_monthly_revenue', 'orders_per_month', 'tax_percent', 'tax_fixed_monthly',
    ];

    protected $casts = [
        'electricity_rate_kwh' => 'decimal:4',
        'labor_hour_rate' => 'decimal:2',
        'default_failure_rate' => 'decimal:2',
        'default_markup' => 'decimal:2',
        'cashback_enabled' => 'boolean',
        'delivery_rules' => 'array',
        'payment_rules' => 'array',
        'cashback_purchase_percent' => 'decimal:2',
        'cashback_review_percent' => 'decimal:2',
        'cashback_referral_percent' => 'decimal:2',
        'cashback_max_redeem_percent' => 'decimal:2',
        'cashback_validity_months' => 'integer',
        'minimum_order_price' => 'decimal:2',
        'order_discount_tiers' => 'array',
        'employees' => 'array',
        'fixed_expenses' => 'array',
        'estimated_monthly_revenue' => 'decimal:2',
        'orders_per_month' => 'integer',
        'tax_percent' => 'decimal:2',
        'tax_fixed_monthly' => 'decimal:2',
    ];

    /** Como a folha (+ imposto fixo mensal) entra no preço. Padrão "none": não muda preço de quem não escolheu. */
    public const FIXED_COSTS_NONE = 'none';

    public const FIXED_COSTS_REVENUE = 'revenue';

    public const FIXED_COSTS_HOUR = 'hour';

    public const FIXED_COSTS_ORDER = 'order';

    public const FIXED_COSTS_MODES = [self::FIXED_COSTS_NONE, self::FIXED_COSTS_REVENUE, self::FIXED_COSTS_HOUR, self::FIXED_COSTS_ORDER];

    /** Teto de imposto + rateio "por dentro" (% do preço): acima disso o preço explode. */
    public const MAX_INSIDE_PERCENT = 90;

    /** Folha do mês: salário × (1 + encargos) de cada funcionário (o pró-labore do dono pode ser uma linha). */
    public function payrollMonthly(): float
    {
        return round(collect($this->employees ?? [])
            ->sum(fn ($e) => (float) ($e['salary'] ?? 0) * (1 + (float) ($e['charges_percent'] ?? 0) / 100)), 2);
    }

    /** Horas trabalhadas no mês, somando todos os funcionários. */
    public function employeeHoursMonthly(): float
    {
        return (float) collect($this->employees ?? [])->sum(fn ($e) => (float) ($e['hours_per_month'] ?? 0));
    }

    /** Despesas fixas da loja no mês (aluguel, água, luz da loja, internet…). */
    public function expensesMonthly(): float
    {
        return round((float) collect($this->fixed_expenses ?? [])->sum(fn ($e) => (float) ($e['amount'] ?? 0)), 2);
    }

    /** O que é rateado: folha + despesas fixas + imposto fixo mensal. */
    public function fixedCostsMonthly(): float
    {
        return round($this->payrollMonthly() + $this->expensesMonthly() + (float) $this->tax_fixed_monthly, 2);
    }

    public function fixedCostsMode(): string
    {
        return in_array($this->fixed_costs_mode, self::FIXED_COSTS_MODES, true) ? $this->fixed_costs_mode : self::FIXED_COSTS_NONE;
    }

    /** "% do faturamento": quanto de cada venda vai para os custos fixos (folha + imposto fixo ÷ faturamento). */
    public function fixedCostsPercent(): float
    {
        $revenue = (float) $this->estimated_monthly_revenue;
        if ($this->fixedCostsMode() !== self::FIXED_COSTS_REVENUE || $revenue <= 0) {
            return 0.0;
        }

        return $this->fixedCostsMonthly() / $revenue * 100;
    }

    /** "Por pedido": valor somado ao total de cada pedido (folha + imposto fixo ÷ pedidos por mês). */
    public function fixedCostPerOrder(): float
    {
        $orders = (int) $this->orders_per_month;
        if ($this->fixedCostsMode() !== self::FIXED_COSTS_ORDER || $orders <= 0) {
            return 0.0;
        }

        return $this->fixedCostsMonthly() / $orders;
    }

    /** Hora de trabalho usada no cálculo: "pela hora" troca o valor digitado pelo custo/hora da folha. */
    public function effectiveLaborRate(): float
    {
        $hours = $this->employeeHoursMonthly();
        if ($this->fixedCostsMode() === self::FIXED_COSTS_HOUR && $hours > 0) {
            return $this->fixedCostsMonthly() / $hours;
        }

        return (float) $this->labor_hour_rate;
    }

    /** Imposto sobre a venda (%). */
    public function taxRate(): float
    {
        return max(0.0, (float) $this->tax_percent);
    }

    /**
     * Imposto e rateio "% do faturamento" entram por dentro (como a comissão do marketplace):
     * preço = valor ÷ (1 − %), pra que, tirados eles do preço, sobre o custo + margem.
     */
    public function priceDivisor(): float
    {
        return 1 - min(self::MAX_INSIDE_PERCENT, $this->taxRate() + $this->fixedCostsPercent()) / 100;
    }

    /** Preço automático de um custo: custo + margem (padrão ou a informada), com imposto e rateio por dentro. */
    public function autoPrice(float|string|null $cost, float|string|null $markup = null): ?float
    {
        if ($cost === null || $cost === '') {
            return null;
        }
        $markup ??= $this->default_markup ?? 0;

        return round((float) $cost * (1 + (float) $markup / 100) / $this->priceDivisor(), 2);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
