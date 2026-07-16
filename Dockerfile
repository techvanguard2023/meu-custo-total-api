# syntax=docker/dockerfile:1

########################################
# Estágio 1: dependências do Composer
########################################
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

########################################
# Estágio 2: runtime (php-fpm + nginx)
########################################
FROM php:8.5-fpm-alpine

# Extensões PHP (pdo_mysql usa mysqlnd → suporta caching_sha2_password)
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql bcmath intl zip opcache

RUN apk add --no-cache nginx supervisor

WORKDIR /app

# Código + vendor
COPY . .
COPY --from=vendor /app/vendor ./vendor

# Configurações do container
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Estrutura de diretórios graváveis + descoberta de pacotes
RUN mkdir -p storage/app/public \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/views \
      storage/logs \
      bootstrap/cache \
    && php artisan package:discover --ansi

# Permissões de escrita do Laravel
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
