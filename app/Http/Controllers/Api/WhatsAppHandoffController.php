<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
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
        ]);

        $phone = $this->digits($data['phone']);
        abort_if(strlen($phone) < 8, 422, 'Telefone inválido.');

        $record = $handoff->start($request->user()->company, $phone, $data['customer_name'] ?? null, $data['reason'] ?? null);

        return response()->json($this->payload($record), 201);
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

        return response()->json(['paused' => false, 'paused_until' => null]);
    }

    private function find(Request $request, string $phone): ?WhatsappHandoff
    {
        return WhatsappHandoff::where('company_id', $request->user()->company_id)
            ->where('phone', $this->digits($phone))
            ->first();
    }

    private function payload(WhatsappHandoff $record): array
    {
        return ['paused' => $record->isPaused(), 'paused_until' => $record->paused_until->toIso8601String()];
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }
}
