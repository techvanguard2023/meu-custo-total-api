<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Support\Collection;

/**
 * Regras de uso dos cupons (Pro): ligado, dentro da validade, limite total, limite por cliente e só na
 * primeira compra. O cliente é reconhecido pelo telefone (pode haver cadastros duplicados). O valor do
 * desconto sai do Coupon::discountFor, chamado pelo cálculo do pedido.
 */
class Coupons
{
    public function find(Company $company, ?string $code): ?Coupon
    {
        $code = Coupon::normalizeCode($code);

        return $code === '' ? null : Coupon::where('company_id', $company->id)->where('code', $code)->first();
    }

    /**
     * Motivo pelo qual o cupom não pode ser usado agora (null = pode). Sem cliente conhecido (`$customer`
     * null e `$customerKnown` false — ex.: carrinho do catálogo sem login), as regras por cliente ficam pra
     * conferência na criação do pedido. `$ignoreQuoteId`: o orçamento sendo editado não conta como uso.
     */
    public function unavailableReason(Coupon $coupon, ?Customer $customer, bool $customerKnown = true, ?int $ignoreQuoteId = null): ?string
    {
        $company = $coupon->company;
        $today = now($company->timezone ?: 'America/Sao_Paulo')->toDateString();

        if (! $company->isPro() || ! $coupon->active) {
            return 'Este cupom não está ativo.';
        }
        if ($coupon->starts_on && $today < $coupon->starts_on->toDateString()) {
            return 'Este cupom vale a partir de '.$coupon->starts_on->format('d/m/Y').'.';
        }
        if ($coupon->ends_on && $today > $coupon->ends_on->toDateString()) {
            return 'Este cupom expirou.';
        }
        if ($coupon->usage_limit !== null && $this->uses($coupon, $ignoreQuoteId) >= $coupon->usage_limit) {
            return 'Este cupom já atingiu o limite de usos.';
        }

        if (! $coupon->per_customer_limit && ! $coupon->first_purchase_only && ! $coupon->customer_id) {
            return null;
        }
        if (! $customer) {
            return $customerKnown ? 'Escolha o cliente: este cupom tem regra por cliente.' : null;
        }

        $customerIds = $this->sameCustomerIds($customer, $company);

        // Cupom pessoal: vale só para o dono (ou um cadastro duplicado dele, pelo telefone)
        if ($coupon->customer_id && ! $customerIds->contains($coupon->customer_id)) {
            return 'Este cupom é pessoal e pertence a outro cliente.';
        }

        if ($coupon->per_customer_limit) {
            $used = $coupon->usedQuotes()->whereIn('customer_id', $customerIds)
                ->when($ignoreQuoteId, fn ($q) => $q->where('id', '!=', $ignoreQuoteId))
                ->count();
            if ($used >= $coupon->per_customer_limit) {
                return $coupon->per_customer_limit === 1 ? 'Este cupom já foi usado por este cliente.' : 'Este cliente já usou este cupom o máximo de vezes.';
            }
        }

        if ($coupon->first_purchase_only) {
            $bought = Quote::whereIn('customer_id', $customerIds)->where('status', Quote::STATUS_APPROVED)
                ->when($ignoreQuoteId, fn ($q) => $q->where('id', '!=', $ignoreQuoteId))
                ->exists();
            if ($bought) {
                return 'Este cupom é só para a primeira compra.';
            }
        }

        return null;
    }

    public function uses(Coupon $coupon, ?int $ignoreQuoteId = null): int
    {
        return $coupon->usedQuotes()->when($ignoreQuoteId, fn ($q) => $q->where('id', '!=', $ignoreQuoteId))->count();
    }

    /** O cliente e os cadastros duplicados dele (mesmo telefone). */
    private function sameCustomerIds(Customer $customer, Company $company): Collection
    {
        $key = Customer::phoneKey($customer->phone);
        if (! $key) {
            return collect([$customer->id]);
        }

        // A empresa vem do cupom: o cliente pode chegar sem a relação carregada (busca por telefone do bot)
        return app(CustomerPortal::class)->customerIds($company, $key)->push($customer->id)->unique()->values();
    }
}
