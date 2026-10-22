<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Display;
use App\Models\DisplayStockLine;
use App\Models\DisplayStockMovement;
use App\Models\Quote;
use App\Services\CustomerPortal;
use App\Services\DisplaySales;
use App\Services\QuoteCalculatorService;
use App\Services\WhatsAppMessenger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Área do parceiro: o dono da loja que tem um expositor entra com o telefone cadastrado no expositor +
 * código recebido no WhatsApp (mesmo login do "Meus pedidos", com escopo próprio). Vê a comissão que já
 * ganhou, o que está no expositor e lança as vendas que fez — elas ficam "a receber" até o repasse, e
 * a loja é avisada no WhatsApp e no sistema. Rotas sem autenticação do painel. Só no plano Pro.
 */
class PartnerPortalController extends Controller
{
    public function __construct(
        private CustomerPortal $portal,
        private DisplaySales $sales,
        private QuoteCalculatorService $calculator,
        private WhatsAppMessenger $messenger,
    ) {}

    public function requestCode(Request $request, string $slug)
    {
        $company = $this->company($slug);
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $wait = $this->portal->requestCode($company, $data['phone'], $request->ip(), CustomerPortal::SCOPE_PARTNER);
        abort_if($wait !== null, 429, $wait);

        // Mesma resposta pra qualquer número — a tela não revela quem é parceiro da loja
        return response()->json(['message' => 'Se esse número for de um parceiro da loja, você vai receber um código no WhatsApp.']);
    }

    public function verifyCode(Request $request, string $slug)
    {
        $company = $this->company($slug);
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $session = $this->portal->verifyCode($company, $data['phone'], $data['code'], CustomerPortal::SCOPE_PARTNER);
        abort_unless($session, 422, 'Código inválido ou expirado. Confira o código ou peça outro.');

        return response()->json(['token' => $session]);
    }

    public function logout(Request $request, string $slug)
    {
        $this->portal->logout($this->company($slug), $request->header('X-Partner-Token'));

        return response()->json(['message' => 'Até logo!']);
    }

    public function show(Request $request, string $slug)
    {
        [$company, $displays] = $this->partner($request, $slug);

        return response()->json([
            'store' => [
                'name' => $company->name,
                'logo_url' => $company->logo_url,
            ],
            'displays' => $displays->map(fn (Display $display) => $this->summary($company, $display))->values(),
        ]);
    }

    /** O parceiro lança uma venda: as unidades saem do expositor na hora e a venda fica a receber. */
    public function storeSale(Request $request, string $slug, int $displayId)
    {
        [$company, $displays] = $this->partner($request, $slug);
        $display = $displays->firstWhere('id', $displayId);
        abort_unless($display, 404, 'Expositor não encontrado.');

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_variation_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $quote = DB::transaction(function () use ($display, $data) {
            $productLines = [];
            $taken = [];

            foreach ($data['lines'] as $row) {
                $stockLine = DisplayStockLine::where('display_id', $display->id)
                    ->where('product_id', $row['product_id'])
                    ->where('product_variation_id', $row['product_variation_id'] ?? null)
                    ->with(['product', 'variation'])
                    ->lockForUpdate()
                    ->first();

                abort_unless($stockLine && $stockLine->product, 422, 'Um dos itens não está mais no expositor. Atualize a página.');

                $label = $stockLine->variation ? "{$stockLine->product->name} ({$stockLine->variation->display_name})" : $stockLine->product->name;
                abort_if(
                    $row['quantity'] > $stockLine->quantity_current,
                    422,
                    "\"{$label}\": o expositor tem {$stockLine->quantity_current} — não dá pra lançar {$row['quantity']}."
                );

                $stockLine->decrement('quantity_current', $row['quantity']);
                $productLines[] = ['product' => $stockLine->product, 'variation' => $stockLine->variation, 'quantity' => (int) $row['quantity']];
                $taken[] = [$stockLine, (int) $row['quantity']];
            }

            $quote = $this->sales->createSale($display, $productLines, DisplaySales::SOURCE_PARTNER);

            foreach ($taken as [$stockLine, $quantity]) {
                DisplayStockMovement::create([
                    'display_id' => $display->id,
                    'product_id' => $stockLine->product_id,
                    'product_variation_id' => $stockLine->product_variation_id,
                    'type' => DisplayStockMovement::TYPE_SALE,
                    'quantity' => $quantity,
                    'quote_id' => $quote->id,
                ]);
            }

            return $quote;
        });

        $this->notifyStore($company, $display, $quote, 'Venda lançada pelo parceiro');

        return response()->json($this->summary($company, $display->fresh()), 201);
    }

    /** O parceiro desfaz uma venda que lançou errado — só no mesmo dia e antes do repasse. */
    public function undoSale(Request $request, string $slug, int $quoteId)
    {
        [$company, $displays] = $this->partner($request, $slug);

        $quote = Quote::whereIn('display_id', $displays->pluck('id'))->with('items')->find($quoteId);
        abort_unless($quote, 404, 'Venda não encontrada.');
        abort_unless($this->canUndo($company, $quote), 422, 'Essa venda não pode mais ser desfeita aqui. Fale com a loja.');

        DB::transaction(function () use ($quote) {
            $this->sales->returnToDisplay($quote);
            $quote->update([
                'status' => Quote::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => 'Desfeita pelo parceiro',
            ]);
        });

        $display = $displays->firstWhere('id', $quote->display_id);
        $this->notifyStore($company, $display, $quote, 'Venda desfeita pelo parceiro');

        return response()->json($this->summary($company, $display->fresh()));
    }

    private function summary(Company $company, Display $display): array
    {
        $timezone = $company->timezone ?: 'America/Sao_Paulo';
        $percent = (float) $display->commission_percent;

        $lines = $display->stockLines()
            ->where('quantity_current', '>', 0)
            ->with(['product', 'variation'])
            ->get()
            ->filter(fn (DisplayStockLine $line) => $line->product !== null)
            ->values();

        // Preço ao consumidor: o mesmo cálculo da venda (promoção vale; preço por quantidade não, no expositor)
        $priced = $lines->isEmpty() ? [] : $this->calculator->calculate(
            ['quantity' => 1],
            [],
            null,
            $company->setting,
            $lines->map(fn ($l) => ['product' => $l->product, 'variation' => $l->variation, 'quantity' => 1])->all(),
            null,
            $display,
        )['products'];

        $items = $lines->map(function (DisplayStockLine $line, int $i) use ($priced, $percent) {
            $price = (float) ($priced[$i]['unit_price'] ?? 0);

            return [
                'product_id' => $line->product_id,
                'product_variation_id' => $line->product_variation_id,
                'name' => $line->product->name,
                'variation' => $line->variation?->display_name,
                'code' => $line->variation?->sku ?: $line->product->sku,
                'image_url' => $line->product->image_url,
                'quantity' => (int) $line->quantity_current,
                'unit_price' => $price,
                'unit_commission' => round($price * $percent / 100, 2),
            ];
        });

        $approved = $display->quotes()->where('status', Quote::STATUS_APPROVED);
        $monthStart = now($timezone)->startOfMonth()->utc();
        $unsettled = $this->sales->unsettled($display);

        $sales = $display->quotes()
            ->whereIn('status', [Quote::STATUS_APPROVED, Quote::STATUS_CANCELLED])
            ->where(fn ($q) => $q->where('status', Quote::STATUS_APPROVED)->orWhere('display_source', DisplaySales::SOURCE_PARTNER))
            ->with('items')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'date' => $quote->created_at?->toIso8601String(),
                'source' => $quote->display_source ?? DisplaySales::SOURCE_RECONCILIATION,
                'cancelled' => $quote->status === Quote::STATUS_CANCELLED,
                'items' => $quote->items->where('type', 'product')->map(fn ($item) => [
                    'description' => $item->description,
                    'quantity' => (int) $item->quantity,
                ])->values(),
                'total' => (float) $quote->final_price,
                'commission' => (float) $quote->display_commission_amount,
                'settled' => $quote->paid_at !== null,
                'can_undo' => $this->canUndo($company, $quote),
            ]);

        return [
            'id' => $display->id,
            'name' => $display->name,
            'commission_percent' => $percent,
            'commission_total' => round((float) (clone $approved)->sum('display_commission_amount'), 2),
            'commission_month' => round((float) (clone $approved)->where('created_at', '>=', $monthStart)->sum('display_commission_amount'), 2),
            'to_settle_amount' => DisplaySales::amountToSettle($unsettled),
            'unsettled_count' => $unsettled->count(),
            'stock_units' => $items->sum('quantity'),
            'stock_value' => round($items->sum(fn ($i) => $i['unit_price'] * $i['quantity']), 2),
            'stock_commission' => round($items->sum(fn ($i) => $i['unit_commission'] * $i['quantity']), 2),
            'items' => $items->values(),
            'sales' => $sales,
        ];
    }

    private function canUndo(Company $company, Quote $quote): bool
    {
        $timezone = $company->timezone ?: 'America/Sao_Paulo';

        return $quote->display_source === DisplaySales::SOURCE_PARTNER
            && $quote->status === Quote::STATUS_APPROVED
            && $quote->paid_at === null
            && $quote->created_at?->copy()->timezone($timezone)->isSameDay(now($timezone));
    }

    /** Aviso no WhatsApp da loja; no sistema, a venda já aparece no expositor e em Vendas como "a receber". */
    private function notifyStore(Company $company, Display $display, Quote $quote, string $title): void
    {
        $money = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $quote->loadMissing('items');
        $toSettle = DisplaySales::amountToSettle($this->sales->unsettled($display));

        $lines = $quote->items->where('type', 'product')
            ->map(fn ($item) => "• {$item->quantity}× {$item->description}")
            ->implode("\n");

        $this->messenger->notifyStore($company, implode("\n", [
            "*{$title}*",
            "Expositor: {$display->name}",
            $lines,
            'Total: '.$money((float) $quote->final_price).' · comissão do parceiro: '.$money((float) $quote->display_commission_amount),
            'A receber desse parceiro agora: '.$money($toSettle),
        ]));
    }

    /** @return array{0: Company, 1: \Illuminate\Support\Collection<int, Display>} */
    private function partner(Request $request, string $slug): array
    {
        $company = $this->company($slug);
        $phoneKey = $this->portal->phoneKeyForToken($company, $request->header('X-Partner-Token'), CustomerPortal::SCOPE_PARTNER);
        abort_unless($phoneKey, 401, 'Sua sessão expirou. Entre de novo com o código do WhatsApp.');

        $displays = $this->portal->partnerDisplays($company, $phoneKey);
        abort_if($displays->isEmpty(), 403, 'Não há expositor ativo ligado a este número.');

        return [$company, $displays];
    }

    private function company(string $slug): Company
    {
        $company = Company::where('slug', $slug)->first();
        abort_unless($company && CustomerPortal::isAvailable($company), 404, 'Área do parceiro indisponível.');

        return $company;
    }
}
