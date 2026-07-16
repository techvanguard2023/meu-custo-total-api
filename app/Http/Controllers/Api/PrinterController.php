<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Printer;
use Illuminate\Http\Request;

class PrinterController extends Controller
{
    use EnforcesPlanLimits;

    public function index(Request $request)
    {
        return $request->user()->company->printers()->latest()->get();
    }

    public function store(Request $request)
    {
        $this->enforceFreeLimit($request, 'printers', $request->user()->company->printers()->count(), 'impressora');

        $data = $this->validated($request);
        $printer = $request->user()->company->printers()->create($data);

        return response()->json($printer, 201);
    }

    public function show(Request $request, Printer $printer)
    {
        $this->authorizeCompany($request, $printer);

        return $printer;
    }

    public function update(Request $request, Printer $printer)
    {
        $this->authorizeCompany($request, $printer);
        $printer->update($this->validated($request));

        return $printer;
    }

    public function destroy(Request $request, Printer $printer)
    {
        $this->authorizeCompany($request, $printer);
        $printer->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'power_watts' => ['required', 'integer', 'min:1'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'lifespan_hours' => ['required', 'integer', 'min:1'],
            'maintenance_percent' => ['sometimes', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function authorizeCompany(Request $request, Printer $printer): void
    {
        abort_unless($printer->company_id === $request->user()->company_id, 403);
    }
}
