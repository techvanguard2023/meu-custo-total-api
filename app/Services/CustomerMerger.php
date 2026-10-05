<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cadastros repetidos do mesmo cliente (mesmo telefone escrito de jeitos
 * diferentes) — acha os grupos e une no cadastro mais antigo, levando junto
 * pedidos e solicitações de orçamento.
 */
class CustomerMerger
{
    /** @return Collection<int, Collection<int, Customer>> grupos com 2+ cadastros, o mais antigo primeiro */
    public function duplicateGroups(?int $companyId = null): Collection
    {
        $query = Customer::query()->whereNotNull('phone')->orderBy('id');
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->get()
            ->filter(fn ($c) => Customer::phoneKey($c->phone) !== null)
            ->groupBy(fn ($c) => $c->company_id.'|'.Customer::phoneKey($c->phone))
            ->filter(fn ($group) => $group->count() > 1)
            ->values();
    }

    /** Une $extras em $keep. Tudo numa transação. */
    public function merge(Customer $keep, iterable $extras): int
    {
        $count = 0;

        DB::transaction(function () use ($keep, $extras, &$count) {
            foreach ($extras as $extra) {
                if ($extra->id === $keep->id || $extra->company_id !== $keep->company_id) {
                    continue;
                }

                Quote::where('customer_id', $extra->id)->update(['customer_id' => $keep->id]);
                QuoteRequest::where('customer_id', $extra->id)->update(['customer_id' => $keep->id]);

                // Aproveita e-mail/observações que só o duplicado tinha.
                $keep->email = $keep->email ?: $extra->email;
                if ($extra->notes && ! str_contains((string) $keep->notes, $extra->notes)) {
                    $keep->notes = trim(($keep->notes ?? '')."\n".$extra->notes);
                }
                $extra->delete();
                $count++;
            }
            $keep->save();
        });

        return $count;
    }
}
