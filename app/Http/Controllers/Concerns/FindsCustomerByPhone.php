<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Customer;
use Illuminate\Http\Request;

/** Usado pelos endpoints do bot de WhatsApp: acha o cliente pelo telefone ou cria um novo. */
trait FindsCustomerByPhone
{
    private function findOrCreateCustomerByPhone(Request $request, string $name, string $phone): Customer
    {
        $key = Customer::phoneKey($phone);
        $company = $request->user()->company;

        // Compara pela chave normalizada (DDD + 8 últimos dígitos), não pelo texto:
        // o mesmo cliente pode estar cadastrado "(21) 97527-8220" e chegar do WhatsApp
        // como "5521975278220". Se houver duplicados antigos, fica o mais antigo.
        $customer = $key === null ? null : $company->customers()
            ->orderBy('id')
            ->get(['id', 'name', 'phone'])
            ->first(fn ($c) => Customer::phoneKey($c->phone) === $key);

        if ($customer) {
            return $customer;
        }

        return $company->customers()->create([
            'name' => $name,
            'phone' => $phone,
        ]);
    }
}
