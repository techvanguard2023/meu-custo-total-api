<?php

namespace App\Services;

use RuntimeException;

/** Erro devolvido pela API do PagBank (o código é o HTTP: 401 = acesso da loja recusado). */
class PagBankException extends RuntimeException {}
