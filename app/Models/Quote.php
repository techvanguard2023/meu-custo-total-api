<?php

namespace App\Models;

use App\Services\ProductRecipe;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRODUCTION_PENDING = 'pending';
    public const PRODUCTION_IN_PRODUCTION = 'in_production';
    public const PRODUCTION_FINISHED = 'finished';
    public const PRODUCTION_DELIVERED = 'delivered';

    public const PRODUCTION_STATUSES = [
        self::PRODUCTION_PENDING,
        self::PRODUCTION_IN_PRODUCTION,
        self::PRODUCTION_FINISHED,
        self::PRODUCTION_DELIVERED,
    ];

    public const PAYMENT_CASH = 'dinheiro';
    public const PAYMENT_PIX = 'pix';
    public const PAYMENT_CREDIT = 'credito';
    public const PAYMENT_DEBIT = 'debito';

    public const PAYMENT_METHODS = [
        self::PAYMENT_CASH,
        self::PAYMENT_PIX,
        self::PAYMENT_CREDIT,
        self::PAYMENT_DEBIT,
    ];

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PARTIAL = 'partial';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_COURTESY = 'courtesy';

    protected $fillable = [
        'company_id', 'customer_id', 'printer_id', 'material_id',
        'name', 'quantity', 'print_time_minutes', 'material_weight_g',
        'setup_minutes', 'postprocess_minutes', 'extra_costs',
        'failure_rate_percent', 'markup_percent', 'discount_amount', 'delivery_days',
        'material_cost', 'energy_cost', 'depreciation_cost', 'labor_cost',
        'failure_cost', 'subtotal_cost', 'final_price', 'unit_price',
        'profit_amount', 'status', 'production_status', 'production_order', 'approved_at',
        'payment_method', 'amount_paid', 'paid_at', 'is_courtesy', 'review_token', 'review_requested_at', 'notified_stages', 'external_reference', 'cancelled_at', 'cancel_reason',
        'model_urls', 'notes', 'paused_at', 'pause_reason',
        'sales_channel_id', 'channel_fee_amount',
        'display_id', 'display_commission_amount', 'display_source',
    ];

    protected $casts = [
        'material_weight_g' => 'decimal:2',
        'model_urls' => 'array',
        'extra_costs' => 'decimal:2',
        'failure_rate_percent' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'material_cost' => 'decimal:2',
        'energy_cost' => 'decimal:2',
        'depreciation_cost' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'failure_cost' => 'decimal:2',
        'subtotal_cost' => 'decimal:2',
        'final_price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'profit_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'channel_fee_amount' => 'decimal:2',
        'display_commission_amount' => 'decimal:2',
        'is_courtesy' => 'boolean',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'review_requested_at' => 'datetime',
        'notified_stages' => 'array',
        'cancelled_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    protected $appends = ['payment_status', 'amount_due'];

    /**
     * Situação do recebimento, derivada do valor — nunca armazenada. Guardar o
     * rótulo junto do valor abriria espaço pros dois discordarem entre si.
     */
    protected function paymentStatus(): Attribute
    {
        return Attribute::get(function () {
            // Cortesia vem antes de tudo: é a única situação que o valor não revela.
            if ($this->is_courtesy) {
                return self::PAYMENT_COURTESY;
            }

            $paid = (float) $this->amount_paid;
            $total = (float) $this->final_price;

            return match (true) {
                $paid <= 0 => self::PAYMENT_UNPAID,
                $paid >= $total => self::PAYMENT_PAID,
                default => self::PAYMENT_PARTIAL,
            };
        });
    }

    /** Quanto ainda falta receber desta venda. Cortesia não gera nada a receber. */
    protected function amountDue(): Attribute
    {
        return Attribute::get(
            fn () => $this->is_courtesy
                ? 0.0
                : round(max(0, (float) $this->final_price - (float) $this->amount_paid), 2)
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Registra a data em que o pedido entrou em cada etapa da produção — é a linha do tempo que o cliente vê
     * em "Meus pedidos". Toda mudança de etapa passa pelo model (criação, Kanban, aprovação, expositor).
     */
    protected static function booted(): void
    {
        static::created(fn (Quote $quote) => $quote->recordStage());
        static::updated(function (Quote $quote) {
            if ($quote->wasChanged('production_status')) {
                $quote->recordStage();
            }

            // Peça sob encomenda vendida sem estoque pronto: os insumos da ficha técnica baixam
            // quando o pedido entra em produção (ou pula direto pra pronto/entregue).
            if (($quote->wasChanged('production_status') || $quote->wasChanged('status'))
                && $quote->status === self::STATUS_APPROVED
                && in_array($quote->production_status, ProductRecipe::CONSUMING_STAGES, true)) {
                app(ProductRecipe::class)->consumeForQuote($quote);
            }
        });
    }

    private function recordStage(): void
    {
        if ($this->production_status) {
            \Illuminate\Support\Facades\DB::table('quote_stage_events')->insert([
                'quote_id' => $this->id,
                'stage' => $this->production_status,
                'created_at' => now(),
            ]);
        }
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(QuoteStageEvent::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    /**
     * O que o cliente efetivamente comprou: os produtos do orçamento (os insumos — filamento,
     * embalagem — são custo interno e não aparecem pro cliente) e, quando há, a peça impressa sob
     * medida. Sem produtos (orçamento de peça avulsa), vira uma linha só com o nome e o valor.
     *
     * @return array<int, array{description: string, quantity: int, unit_price: float, amount: float}>
     */
    public function soldLines(): array
    {
        $products = $this->items->where('type', 'product');
        $name = trim(explode('|__JSON__|', (string) $this->name)[0]) ?: "Venda #{$this->id}";
        $quantity = max(1, (int) $this->quantity);

        if ($products->isEmpty()) {
            return [[
                'description' => $name,
                'quantity' => $quantity,
                'unit_price' => round((float) $this->final_price / $quantity, 2),
                'amount' => (float) $this->final_price,
            ]];
        }

        $lines = $products->map(fn ($i) => [
            'description' => $i->description,
            'quantity' => (int) $i->quantity,
            'unit_price' => (float) $i->unit_price,
            'amount' => (float) $i->amount,
        ])->values()->all();

        // Pedido que mistura produtos prontos E uma peça impressa sob medida: a peça impressa é o que
        // sobra do total depois dos produtos — sem esta linha ela sumia (o cliente via itens somando
        // menos que o total, no PDF, no perfil e em "Meus pedidos").
        if ((int) $this->print_time_minutes > 0) {
            $printed = round((float) $this->final_price - $products->sum(fn ($i) => (float) $i->amount), 2);
            if ($printed > 0) {
                $lines[] = [
                    'description' => $name,
                    'quantity' => $quantity,
                    'unit_price' => round($printed / $quantity, 2),
                    'amount' => $printed,
                ];
            }
        }

        return $lines;
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function display(): BelongsTo
    {
        return $this->belongsTo(Display::class);
    }
}
