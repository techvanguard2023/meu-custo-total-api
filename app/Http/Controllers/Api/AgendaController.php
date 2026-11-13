<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\DeliveryAgenda;
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

    public function index(Request $request, DeliveryAgenda $agenda)
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
        $overdue = $request->boolean('overdue') ? $agenda->overdue($company) : collect();

        return [
            'today' => now(Delivery::TZ)->toDateString(),
            'quotes' => $agenda->between($company, $from, $to)->map(fn (Quote $q) => $this->row($q, $request->user()->seesPrices())),
            'overdue' => $overdue->map(fn (Quote $q) => $this->row($q, $request->user()->seesPrices())),
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
