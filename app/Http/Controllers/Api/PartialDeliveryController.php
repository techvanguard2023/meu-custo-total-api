<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuotePartialDelivery;
use App\Services\ProductionStageNotifier;
use App\Services\WhatsAppMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Entrega dividida: o pedido tem vários itens, uns prontos e outros esperando insumo. A loja entrega o que está
 * pronto e registra aqui. O pedido continua um só (valor, recebimento e estoque não mudam); cada item guarda quanto
 * já saiu. Entregue tudo, o pedido vai sozinho para "Entregue ao Cliente" (com o aviso de entrega de sempre).
 */
class PartialDeliveryController extends Controller
{
    public function index(Request $request, Quote $quote): JsonResponse
    {
        $this->authorizeQuote($request, $quote);

        return response()->json($this->payload($quote));
    }

    /** POST /quotes/{quote}/partial-deliveries {items: [{item_id, quantity}], note?, notify?} */
    public function store(Request $request, Quote $quote, WhatsAppMessenger $messenger): JsonResponse
    {
        $this->authorizeQuote($request, $quote);
        abort_unless($quote->status === Quote::STATUS_APPROVED && $quote->cancelled_at === null, 422, 'Só dá para entregar parte de uma venda aprovada.');
        abort_if($quote->production_status === Quote::PRODUCTION_DELIVERED, 422, 'Este pedido já foi entregue.');

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
            'notify' => ['sometimes', 'boolean'],
        ], [
            'items.required' => 'Escolha o que está sendo entregue.',
            'items.min' => 'Escolha o que está sendo entregue.',
        ]);

        [$delivery, $completed] = DB::transaction(function () use ($request, $quote, $data) {
            // Trava os itens: duas telas registrando ao mesmo tempo não passam do total
            $items = $quote->items()->where('type', 'product')->lockForUpdate()->get()->keyBy('id');
            $lines = [];

            foreach (collect($data['items'])->groupBy('item_id') as $itemId => $rows) {
                /** @var QuoteItem|null $item */
                $item = $items->get((int) $itemId);
                abort_unless($item, 422, 'Item não encontrado neste pedido.');
                $quantity = (int) $rows->sum('quantity');
                $left = $item->quantity - $item->delivered_quantity;
                abort_if($quantity > $left, 422, $left > 0
                    ? "\"{$item->description}\": faltam só {$left} para entregar."
                    : "\"{$item->description}\" já foi entregue por completo.");

                $item->increment('delivered_quantity', $quantity);
                $lines[] = ['item_id' => $item->id, 'description' => $item->description, 'quantity' => $quantity];
            }

            $delivery = QuotePartialDelivery::create([
                'company_id' => $quote->company_id,
                'quote_id' => $quote->id,
                'user_id' => $request->user()->id,
                'items' => $lines,
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
            ]);

            // Saiu tudo (e não há peça sob medida fora dos itens): o pedido está entregue
            $completed = (int) $quote->print_time_minutes === 0
                && $items->every(fn (QuoteItem $i) => $i->fresh()->delivered_quantity >= $i->quantity);

            return [$delivery, $completed];
        });

        if ($completed) {
            // Mesmo caminho de arrastar para "Entregue": fim da coluna e o aviso de entrega da loja
            $previous = $quote->production_status;
            $last = (int) Quote::where('company_id', $quote->company_id)->where('production_status', Quote::PRODUCTION_DELIVERED)->max('production_order');
            $quote->update(['production_status' => Quote::PRODUCTION_DELIVERED, 'production_order' => $last + 1]);
            app(ProductionStageNotifier::class)->stageChanged($quote, $previous);
        } elseif ($request->boolean('notify')) {
            $result = $messenger->deliver($quote->company, $quote->customer?->phone, $this->customerMessage($quote->fresh(['items', 'customer', 'company']), $delivery));
            $delivery->forceFill(['customer_notified' => $result['sent']])->save();
            $notifyReason = $result['sent'] ? null : $result['reason'];
        }

        return response()->json($this->payload($quote->fresh()) + [
            'completed' => $completed,
            'notified' => $delivery->fresh()->customer_notified,
            'notify_reason' => $notifyReason ?? null,
        ], 201);
    }

    /** Desfaz uma entrega registrada por engano (os itens voltam a faltar). */
    public function destroy(Request $request, Quote $quote, QuotePartialDelivery $delivery): JsonResponse
    {
        $this->authorizeQuote($request, $quote);
        abort_unless($delivery->quote_id === $quote->id, 404);
        abort_if($quote->production_status === Quote::PRODUCTION_DELIVERED, 422, 'O pedido já está como entregue. Volte o estágio na Produção antes de desfazer uma entrega.');

        DB::transaction(function () use ($quote, $delivery) {
            $items = $quote->items()->lockForUpdate()->get()->keyBy('id');
            foreach ($delivery->items as $line) {
                if ($item = $items->get($line['item_id'])) {
                    $item->update(['delivered_quantity' => max(0, $item->delivered_quantity - (int) $line['quantity'])]);
                }
            }
            $delivery->delete();
        });

        return response()->json($this->payload($quote->fresh()));
    }

    private function authorizeQuote(Request $request, Quote $quote): void
    {
        abort_unless($quote->company_id === $request->user()->company_id, 403);
    }

    private function payload(Quote $quote): array
    {
        $quote->load(['partialDeliveries.user:id,name', ...Product::displayRelations('items.product')]);

        return [
            'production_status' => $quote->production_status,
            'items' => $quote->items->where('type', 'product')->map(fn (QuoteItem $i) => [
                'id' => $i->id,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'delivered_quantity' => $i->delivered_quantity,
            ])->values(),
            'deliveries' => $quote->partialDeliveries->map(fn (QuotePartialDelivery $d) => [
                'id' => $d->id,
                'items' => $d->items,
                'note' => $d->note,
                'customer_notified' => $d->customer_notified,
                'by' => $d->user?->name,
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** "Entregamos parte do seu pedido": o que saiu agora e o que ainda falta. */
    private function customerMessage(Quote $quote, QuotePartialDelivery $delivery): string
    {
        $first = explode(' ', trim((string) $quote->customer?->name))[0];
        $now = collect($delivery->items)->map(fn ($l) => "{$l['quantity']}x {$l['description']}")->implode(', ');
        $left = $quote->items->where('type', 'product')
            ->filter(fn (QuoteItem $i) => $i->delivered_quantity < $i->quantity)
            ->map(fn (QuoteItem $i) => ($i->quantity - $i->delivered_quantity)."x {$i->description}")
            ->implode(', ');

        return 'Olá'.($first !== '' ? ", {$first}" : '')."! Entregamos parte do seu pedido *#{$quote->id}*: {$now}."
            .($left !== '' ? " Ainda falta: {$left}. Avisamos por aqui quando o restante estiver pronto." : '')
            .' '.($quote->company->name ?: '');
    }
}
