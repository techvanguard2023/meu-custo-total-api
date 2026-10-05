<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\WhatsappHandoff;
use App\Services\HumanHandoff;
use Illuminate\Http\Request;

/**
 * Pausa do bot por conversa. O n8n consulta antes de responder e registra
 * quando o cliente precisa de uma pessoa (mesmo token de integração).
 */
class WhatsAppHandoffController extends Controller
{
    use EnforcesPlanLimits;

    public function store(Request $request, HumanHandoff $handoff)
    {
        $this->requirePro($request, 'Conexão com WhatsApp');

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
            // Pausa pedida pela tela (indefinida e sem aviso no WhatsApp) — o bot nunca manda isso.
            'indefinite' => ['sometimes', 'boolean'],
            'notify' => ['sometimes', 'boolean'],
        ]);

        $phone = $this->normalizePhone($data['phone']);
        abort_if(strlen($phone) < 8, 422, 'Telefone inválido.');

        $record = $handoff->start(
            $request->user()->company,
            $phone,
            $data['customer_name'] ?? null,
            $data['reason'] ?? null,
            (bool) ($data['indefinite'] ?? false),
            (bool) ($data['notify'] ?? true),
        );

        return response()->json($this->payload($record), 201);
    }

    /** Conversas com o bot pausado agora (a tela de Integrações lista e permite reativar). */
    public function index(Request $request)
    {
        $this->requirePro($request, 'Conexão com WhatsApp');

        $companyId = $request->user()->company_id;
        $customers = Customer::where('company_id', $companyId)->get(['name', 'phone']);

        return response()->json(
            WhatsappHandoff::where('company_id', $companyId)
                ->where('paused_until', '>', now())
                ->latest('updated_at')
                ->get()
                ->map(function (WhatsappHandoff $h) use ($customers) {
                    $key = Customer::phoneKey($h->phone);
                    $customer = $key === null ? null : $customers->first(fn ($c) => Customer::phoneKey($c->phone) === $key);

                    return [
                        'phone' => $h->phone,
                        'name' => $customer?->name ?? $h->customer_name,
                        'reason' => $h->reason,
                        'paused_until' => $h->paused_until->toIso8601String(),
                        'indefinite' => $this->isIndefinite($h),
                    ];
                })
                ->values()
        );
    }

    public function show(Request $request, string $phone)
    {
        $this->requirePro($request, 'Conexão com WhatsApp');

        $record = $this->find($request, $phone);

        return response()->json($record ? $this->payload($record) : ['paused' => false, 'paused_until' => null]);
    }

    /** Devolve a conversa ao bot antes das 4h. */
    public function destroy(Request $request, string $phone)
    {
        $this->requirePro($request, 'Conexão com WhatsApp');

        $this->find($request, $phone)?->delete();

        return response()->json(['paused' => false, 'paused_until' => null, 'indefinite' => false]);
    }

    /** Acha a pausa mesmo que o telefone esteja escrito diferente (com/sem 55, com/sem nono dígito). */
    private function find(Request $request, string $phone): ?WhatsappHandoff
    {
        $digits = $this->digits($phone);
        $key = Customer::phoneKey($phone);

        return WhatsappHandoff::where('company_id', $request->user()->company_id)->get()
            ->first(fn ($h) => $h->phone === $digits || ($key !== null && Customer::phoneKey($h->phone) === $key));
    }

    private function payload(WhatsappHandoff $record): array
    {
        return [
            'paused' => $record->isPaused(),
            'paused_until' => $record->paused_until->toIso8601String(),
            'indefinite' => $this->isIndefinite($record),
        ];
    }

    private function isIndefinite(WhatsappHandoff $record): bool
    {
        return $record->paused_until->gt(now()->addYear());
    }

    /** Telefone brasileiro sem DDI (10–11 dígitos) ganha o 55 — é o formato que o WhatsApp usa. */
    private function normalizePhone(string $value): string
    {
        $digits = $this->digits($value);

        return in_array(strlen($digits), [10, 11], true) ? '55'.$digits : $digits;
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }
}
