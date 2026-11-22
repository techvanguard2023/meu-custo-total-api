<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * O gateway não pode gerar esta cobrança por falta de um dado (ex.: CPF do cliente no Pix do PagBank).
 * A mensagem é a frase para o lojista ou o cliente — diferente de erro de API, que vai só para o log.
 */
class PaymentRefused extends RuntimeException {}
