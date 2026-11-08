<?php

namespace App\Services;

use RuntimeException;

/** Erro devolvido pela API do Mercado Pago (o código é o status HTTP: 401 = acesso da loja recusado). */
class MercadoPagoException extends RuntimeException {}
