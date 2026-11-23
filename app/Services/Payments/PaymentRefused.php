<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * O gateway não pode gerar esta cobrança por falta de um dado (ex.: CPF do cliente no Pix do PagBank).
 * A mensagem é a frase para o lojista — diferente de erro de API, que vai só para o log. $missing diz qual dado
 * falta, para a tela do cliente ("Meus pedidos") pedir esse dado a ele.
 */
class PaymentRefused extends RuntimeException
{
    /** Falta o CPF ou CNPJ do cliente */
    public const TAX_ID = 'tax_id';

    public function __construct(string $message, public readonly ?string $missing = null)
    {
        parent::__construct($message);
    }
}
