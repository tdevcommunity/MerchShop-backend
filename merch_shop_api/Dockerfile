# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Etape 1 : compilation des assets Vite/Tailwind (laravel-vite-plugin ecrit
# dans public/build, consomme par @vite dans resources/views/welcome.blade.php)
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /build

# npm install et non npm ci : le depot ne versionne pas de package-lock.json
# cote back (seul composer.lock est present), et npm ci echoue sans lockfile.
COPY package.json ./
RUN npm install

COPY vite.config.js ./
COPY resources/ ./resources/

RUN npm run build


# ---------------------------------------------------------------------------
# Etape 2 : runtime PHP
#
# PHP 8.4 et non 8.3 : composer.lock se declare "php": "^8.3" mais epingle
# symfony/* en v8.1.8, qui exige ">=8.4.1". "^8.3" autorise 8.4, donc cette image
# satisfait composer.json sans toucher au lock. Sur du 8.3, composer install
# echoue sur une vingtaine de paquets Symfony.
# ---------------------------------------------------------------------------
FROM php:8.4-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive

# Extensions requises :
#   gd         -> endroid/qr-code (QR de retrait)
#   pdo_pgsql  -> PostgreSQL
#   redis      -> REDIS_CLIENT=phpredis, absent de composer.lock donc pas de predis
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependances d'abord, en differe : la couche est reutilisee tant que
# composer.json / composer.lock ne changent pas.
COPY composer.json composer.lock ./
RUN composer install \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

COPY . .
COPY --from=assets /build/public/build ./public/build

# APP_KEY provisoire : la cle reelle vient du .env monte par docker-compose.
# Ce placeholder evite que les commandes artisan du build echouent.
RUN printf 'APP_ENV=local\nAPP_KEY=base64:buildplaceholder0000000000000000000000=\n' > .env

# package:discover / dump-autoload ont besoin de l'arborescence complete.
# HOME est positionne et prepare car psysh (artisan tinker) refuse d'ecrire dans
# /var/www/.config sans cela, ce qui rend tinker inutilisable dans le conteneur.
RUN composer dump-autoload --optimize --no-interaction \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /home/www-data/.config/psysh \
    && chown -R www-data:www-data storage bootstrap/cache /home/www-data

ENV HOME=/home/www-data

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

USER www-data

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
