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

        // A busca acima traz só id/nome/telefone (para comparar rápido): devolve o cadastro completo, senão quem usa
        // depois fica sem company_id, indicação, etc. (ex.: a indicação do pedido do bot quebrava com 500).
        if ($customer) {
            return $company->customers()->findOrFail($customer->id);
        }

        return $company->customers()->create([
            'name' => $name,
            'phone' => $phone,
        ]);
    }
}
