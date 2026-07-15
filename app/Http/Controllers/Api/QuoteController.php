<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Printer;
use App\Models\Quote;
use App\Services\QuoteCalculatorService;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    public function __construct(private QuoteCalculatorService $calculator) {}

    public function index(Request $request)
    {
        return $request->user()->company->quotes()
            ->with(['customer', 'printer', 'material'])
            ->latest()
            ->get();
    }

    public function show(Request $request, Quote $quote)
    {
        $this->authorizeCompany($request, $quote);

        return $quote->load(['customer', 'printer', 'material', 'items']);
    }

    public function preview(Request $request)
    {
        $data = $this->validated($request);
        [$material, $printer] = $this->resolveEntities($request, $data);
        $setting = $request->user()->company->setting;

        return response()->json(
            $this->calculator->calculate($data, $material, $printer, $setting)
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        [$material, $printer] = $this->resolveEntities($request, $data);
        $setting = $request->user()->company->setting;

        $breakdown = $this->calculator->calculate($data, $material, $printer, $setting);

        $quote = $request->user()->company->quotes()->create(
            $this->quoteAttributes($data, $breakdown, Quote::STATUS_SENT)
        );

        return response()->json($quote->load(['customer', 'printer', 'material']), 201);
    }

    public function update(Request $request, Quote $quote)
    {
        $this->authorizeCompany($request, $quote);

        abort_if(
            in_array($quote->status, [Quote::STATUS_APPROVED, Quote::STATUS_REJECTED], true),
            422,
            'Orçamentos aprovados ou rejeitados não podem ser editados.'
        );

        $data = $this->validated($request);
        [$material, $printer] = $this->resolveEntities($request, $data);
        $setting = $request->user()->company->setting;

        $breakdown = $this->calculator->calculate($data, $material, $printer, $setting);

        $quote->update($this->quoteAttributes($data, $breakdown, $quote->status));

        return response()->json($quote->fresh()->load(['customer', 'printer', 'material']));
    }

    public function destroy(Request $request, Quote $quote)
    {
        $this->authorizeCompany($request, $quote);

        abort_if($quote->status === Quote::STATUS_APPROVED, 422, 'Orçamentos aprovados (vendas) não podem ser excluídos.');

        $quote->delete();

        return response()->json(null, 204);
    }

    public function approve(Request $request, Quote $quote)
    {
        return $this->transitionStatus($request, $quote, Quote::STATUS_APPROVED);
    }

    public function reject(Request $request, Quote $quote)
    {
        return $this->transitionStatus($request, $quote, Quote::STATUS_REJECTED);
    }

    private function transitionStatus(Request $request, Quote $quote, string $status)
    {
        $this->authorizeCompany($request, $quote);

        abort_unless($quote->status === Quote::STATUS_SENT, 422, 'Apenas orçamentos enviados podem ser aprovados ou rejeitados.');

        $quote->update(['status' => $status]);

        return response()->json($quote->fresh()->load(['customer', 'printer', 'material']));
    }

    private function quoteAttributes(array $data, array $breakdown, string $status): array
    {
        return array_merge($data, [
            'material_cost' => $breakdown['material_cost'],
            'energy_cost' => $breakdown['energy_cost'],
            'depreciation_cost' => $breakdown['depreciation_cost'],
            'labor_cost' => $breakdown['labor_cost'],
            'failure_rate_percent' => $breakdown['failure_rate_percent'],
            'failure_cost' => $breakdown['failure_cost'],
            'subtotal_cost' => $breakdown['subtotal_cost'],
            'markup_percent' => $breakdown['markup_percent'],
            'final_price' => $breakdown['final_price'],
            'unit_price' => $breakdown['unit_price'],
            'profit_amount' => $breakdown['profit_amount'],
            'status' => $status,
        ]);
    }

    private function resolveEntities(Request $request, array $data): array
    {
        $companyId = $request->user()->company_id;

        $material = isset($data['material_id'])
            ? Material::where('company_id', $companyId)->findOrFail($data['material_id'])
            : null;

        $printer = isset($data['printer_id'])
            ? Printer::where('company_id', $companyId)->findOrFail($data['printer_id'])
            : null;

        return [$material, $printer];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'printer_id' => ['nullable', 'exists:printers,id'],
            'material_id' => ['nullable', 'exists:materials,id'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'print_time_minutes' => ['required', 'integer', 'min:1'],
            'material_weight_g' => ['required', 'numeric', 'min:0'],
            'setup_minutes' => ['sometimes', 'integer', 'min:0'],
            'postprocess_minutes' => ['sometimes', 'integer', 'min:0'],
            'extra_costs' => ['sometimes', 'numeric', 'min:0'],
            'failure_rate_percent' => ['nullable', 'numeric', 'min:0'],
            'markup_percent' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['sometimes', 'numeric', 'min:0'],
        ]);
    }

    private function authorizeCompany(Request $request, Quote $quote): void
    {
        abort_unless($quote->company_id === $request->user()->company_id, 403);
    }
}
