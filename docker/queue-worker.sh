#!/bin/sh
# Worker da fila (chamado pelo supervisor). $1 = conexão (redis | database), $2 = segundos de espera com a fila vazia.
#
# A conexão "failover" grava no Redis e, se ele estiver fora, no MySQL — mas cada worker lê de uma conexão só,
# por isso são dois: o do Redis (rápido) e o do MySQL (pega o que caiu na reserva).
# Enquanto QUEUE_BACKGROUND não for "true" as tarefas rodam depois da resposta, como antes, e o worker só espera.
cd /app

if [ "${QUEUE_BACKGROUND:-false}" != "true" ]; then
    echo "[queue-worker $1] QUEUE_BACKGROUND desligado: tarefas rodam depois da resposta, worker parado"
    exec tail -f /dev/null
fi

if [ "$1" = "redis" ] && [ -z "${REDIS_HOST:-}" ]; then
    echo "[queue-worker redis] REDIS_HOST não configurado: worker do Redis parado (o do MySQL segue)"
    exec tail -f /dev/null
fi

# --timeout abaixo do retry_after (90 s) da conexão, senão a tarefa podia rodar duas vezes
exec php artisan queue:work "$1" --sleep="${2:-3}" --tries=3 --backoff=10 --timeout=80 --max-time=3600 --memory=200
