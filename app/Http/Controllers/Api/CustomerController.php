<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->company->customers()->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $customer = $request->user()->company->customers()->create($data);

        return response()->json($customer, 201);
    }

    public function show(Request $request, Customer $customer)
    {
        $this->authorizeCompany($request, $customer);

        return $customer;
    }

    public function update(Request $request, Customer $customer)
    {
        $this->authorizeCompany($request, $customer);
        $customer->update($this->validated($request));

        return $customer;
    }

    public function destroy(Request $request, Customer $customer)
    {
        $this->authorizeCompany($request, $customer);
        $customer->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function authorizeCompany(Request $request, Customer $customer): void
    {
        abort_unless($customer->company_id === $request->user()->company_id, 403);
    }
}
