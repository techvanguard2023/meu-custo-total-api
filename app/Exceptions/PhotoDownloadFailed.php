<?php

namespace App\Exceptions;

use RuntimeException;

/** Foto da planilha que não deu para baixar — a mensagem é o motivo em português, mostrado na tela da importação. */
class PhotoDownloadFailed extends RuntimeException {}
