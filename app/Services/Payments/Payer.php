<?php

namespace App\Services\Payments;

use App\Models\Customer;

/** Quem paga: o que cada gateway pode pedir do comprador (o Mercado Pago só o e-mail; o PagBank, nome e CPF/CNPJ). */
final class Payer
{
    /** Sem e-mail do cliente, vai o da plataforma (o banco do pagador é quem identifica quem pagou). */
    public const FALLBACK_EMAIL = 'pagamentos@meucustototal.com.br';

    public function __construct(
        public readonly string $name,
        public readonly string $email,
        /** CPF (11) ou CNPJ (14), só dígitos; null = não cadastrado */
        public readonly ?string $taxId,
        /** Telefone só com dígitos, com DDD (sem o 55) */
        public readonly ?string $phone,
    ) {}

    public static function fromCustomer(?Customer $customer): self
    {
        $email = $customer?->email && filter_var($customer->email, FILTER_VALIDATE_EMAIL) ? $customer->email : self::FALLBACK_EMAIL;
        $taxId = preg_replace('/\D/', '', (string) $customer?->tax_id);
        $phone = preg_replace('/\D/', '', (string) $customer?->phone);
        if (strlen($phone) > 11 && str_starts_with($phone, '55')) {
            $phone = substr($phone, 2);
        }

        return new self(
            name: trim((string) $customer?->name) ?: 'Cliente',
            email: $email,
            taxId: in_array(strlen($taxId), [11, 14], true) ? $taxId : null,
            phone: strlen($phone) >= 10 ? $phone : null,
        );
    }
}
