<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * O gateway não gerou a cobrança (fora do ar, recusou, erro de API — o detalhe vai para o log). A mensagem é a
 * frase para o lojista; a tela do cliente mostra uma frase própria.
 */
class GatewayFailed extends RuntimeException {}
