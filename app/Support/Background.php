<?php

namespace App\Support;

use Closure;

/**
 * Tarefa que não precisa segurar a resposta (WhatsApp, e-mail). Com QUEUE_BACKGROUND=true vai pra fila: roda num
 * worker separado, sobrevive a um deploy no meio e tenta de novo se falhar (e-mail fora do ar, por exemplo).
 * Sem a variável — ou onde não há worker, como no ambiente local — roda logo depois da resposta, como antes.
 *
 * A closure é serializada pra fila: use só ids e valores simples (ou models, que vão como referência) e chame
 * os serviços por app(...) dentro dela.
 */
class Background
{
    public static function run(Closure $task): void
    {
        if (config('queue.background')) {
            // afterCommit: se for chamado dentro de uma transação, o worker só pega depois do commit
            // (senão poderia rodar antes e não achar o orçamento/chamado ainda)
            dispatch($task)->afterCommit();

            return;
        }

        dispatch($task)->afterResponse();
    }
}
