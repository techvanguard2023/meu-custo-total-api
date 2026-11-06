<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Support\Delivery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Agenda (Pro): vendas aprovadas com data de entrega num período, mais as atrasadas (data já passou e ainda não
 * entregues). Serve a tela de Agenda (mês, semana, lista) e o quadro "Entregas" do Dashboard (hoje, amanhã e
 * atrasadas). Produção vê sem valores (TeamAccess tira os preços).
 */
class AgendaController extends Controller
{
    use EnforcesPlanLimits;

    public const MAX_DAYS = 62;

    public function index(Request $request)
    {
        $this->requirePro($request, 'Agenda');

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'overdue' => ['sometimes', 'boolean'],
        ]);
        $from = Carbon::parse($data['from'], Delivery::TZ);
        $to = Carbon::parse($data['to'], Delivery::TZ);
        abort_if($from->diffInDays($to) > self::MAX_DAYS, 422, 'Período muito longo (máximo '.self::MAX_DAYS.' dias).');

        $company = $request->user()->company;
        $base = fn () => $company->quotes()
            ->where('status', Quote::STATUS_APPROVED)
            ->whereNotNull('delivery_date')
            ->with(['customer:id,name,phone', 'items']);

        $inRange = $base()->whereBetween('delivery_date', [$from->toDateString(), $to->toDateString()])->get();
        $overdue = $request->boolean('overdue')
            ? $base()->where('delivery_date', '<', now(Delivery::TZ)->toDateString())
                ->where('production_status', '!=', Quote::PRODUCTION_DELIVERED)->get()
            : collect();

        $sort = fn ($quotes) => $quotes->sortBy(fn (Quote $q) => $q->delivery_date->toDateString().' '.($q->delivery_time ?? '99:99'))->values();

        return [
            'today' => now(Delivery::TZ)->toDateString(),
            'quotes' => $sort($inRange)->map(fn (Quote $q) => $this->row($q, $request->user()->seesPrices())),
            'overdue' => $sort($overdue)->map(fn (Quote $q) => $this->row($q, $request->user()->seesPrices())),
        ];
    }

    /** Produção trabalha sem valores e sem recebimento: a situação do pagamento também fica de fora. */
    private function row(Quote $q, bool $withPayment): array
    {
        return [
            'id' => $q->id,
            'name' => trim(explode('|__JSON__|', (string) $q->name)[0]) ?: "Venda #{$q->id}",
            'customer' => $q->customer ? ['name' => $q->customer->name, 'phone' => $q->customer->phone] : null,
            'items' => collect($q->soldLines())->map(fn ($l) => $l['quantity'].'x '.$l['description'])->implode(', '),
            'delivery_date' => $q->delivery_date->toDateString(),
            'delivery_time' => $q->delivery_time,
            'delivery_method' => $q->delivery_method,
            'delivery_address' => $q->delivery_address,
            'production_status' => $q->production_status,
            'paused' => $q->paused_at !== null,
        ] + ($withPayment ? [
            'payment_status' => $q->payment_status,
            'amount_due' => (float) $q->amount_due,
            'final_price' => (float) $q->final_price,
        ] : []);
    }
}
