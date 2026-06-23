# Symfony app image for the Absence Run — runs the console command, the Messenger
# worker, and the bundled mock HR API. CLI only (no web server).
FROM php:8.4-cli AS app

# System libraries needed by the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libpq-dev libzip-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip mbstring opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=dev \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Install dependencies first (better layer caching), then copy the source.
# Dev dependencies are included because the stack runs in APP_ENV=dev (fixtures,
# dev-only bundles). A production image would use --no-dev with APP_ENV=prod.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

# Keep the container alive so `docker compose exec app …` works; worker/hr-api override this.
CMD ["tail", "-f", "/dev/null"]
