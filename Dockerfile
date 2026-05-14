FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

FROM php:8.4-cli-bookworm

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libreoffice-core \
        libreoffice-writer \
        default-jre-headless \
        fonts-dejavu-core \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN rm -f bootstrap/cache/*.php
RUN php artisan package:discover --ansi

CMD ["php", "artisan", "jobs:convert-docx-to-pdf", "--no-interaction"]
