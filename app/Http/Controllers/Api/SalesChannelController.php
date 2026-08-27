<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesChannel;
use Illuminate\Http\Request;

/**
 * Canais de venda (Mercado Livre, Shopee...) cadastrados pelo lojista, com a
 * comissão + taxa fixa que ele vê no relatório de repasse do marketplace.
 * Usado pela calculadora de orçamento para embutir o preço de tabela certo.
 */
class SalesChannelController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->company->salesChannels()->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $channel = $request->user()->company->salesChannels()->create($data);

        return response()->json($channel, 201);
    }

    public function update(Request $request, SalesChannel $salesChannel)
    {
        $this->authorizeCompany($request, $salesChannel);

        $data = $this->validated($request);
        $data['active'] = $request->boolean('active', $salesChannel->active);

        $salesChannel->update($data);

        return response()->json($salesChannel->fresh());
    }

    /** Sem bloqueio por vendas existentes: o FK em quotes é nullOnDelete e guarda o valor já cobrado. */
    public function destroy(Request $request, SalesChannel $salesChannel)
    {
        $this->authorizeCompany($request, $salesChannel);

        $salesChannel->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'fixed_fee' => ['sometimes', 'numeric', 'min:0'],
        ]);
    }

    private function authorizeCompany(Request $request, SalesChannel $channel): void
    {
        abort_unless($channel->company_id === $request->user()->company_id, 403);
    }
}
