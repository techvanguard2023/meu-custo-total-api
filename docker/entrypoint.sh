#!/bin/sh
set -e

cd /app

# Cache de config/rotas com as variáveis de ambiente do runtime
php artisan config:cache || echo "[entrypoint] config:cache falhou (seguindo sem cache)"
php artisan route:cache || echo "[entrypoint] route:cache falhou (seguindo sem cache)"

# Link público para as imagens de produto
php artisan storage:link || true

# Migrations automáticas (desative com AUTO_MIGRATE=false)
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    php artisan migrate --force || echo "[entrypoint] migrate falhou — verifique a conexão com o banco"
fi

exec supervisord -c /etc/supervisord.conf
