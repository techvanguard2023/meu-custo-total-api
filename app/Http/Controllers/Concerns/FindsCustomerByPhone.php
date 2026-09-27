<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Customer;
use Illuminate\Http\Request;

/** Usado pelos endpoints do bot de WhatsApp: acha o cliente pelo telefone (só os dígitos) ou cria um novo. */
trait FindsCustomerByPhone
{
    private function findOrCreateCustomerByPhone(Request $request, string $name, string $phone): Customer
    {
        $digits = preg_replace('/\D/', '', $phone);

        $customer = $request->user()->company->customers()
            ->get(['id', 'name', 'phone'])
            ->first(fn ($c) => $c->phone && preg_replace('/\D/', '', $c->phone) === $digits);

        if ($customer) {
            return $customer;
        }

        return $request->user()->company->customers()->create([
            'name' => $name,
            'phone' => $phone,
        ]);
    }
}
