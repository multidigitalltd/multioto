# Multioto — single image used for the web, queue and scheduler containers.
FROM php:8.3-cli

# System deps + PHP extensions (PostgreSQL, Redis, zip, gd, intl, bcmath, gmp).
# gmp is needed by the Web Push (VAPID) crypto in minishlink/web-push.
# pgsql sits alongside pdo_pgsql for ONE reason: it is the only way to ask the
# database a question with a deadline we hold ourselves (pg_send_query +
# pg_connection_busy). /health uses it so a backend that answers the network and
# never returns a result ends as a 503 rather than a hung request.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libpng-dev libicu-dev libgmp-dev \
    && docker-php-ext-install pdo_pgsql pgsql zip gd intl bcmath gmp pcntl calendar \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Raise PHP upload/runtime limits (large legacy-import CSVs via `php artisan serve`).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-multioto.ini

# Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies first (better layer caching).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# App code.
COPY . .
RUN composer dump-autoload --optimize \
    && php artisan filament:assets \
    && chmod +x docker/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["web"]
