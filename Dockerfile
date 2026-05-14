FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --classmap-authoritative \
    --no-scripts

FROM php:8.5-cli-bookworm

WORKDIR /app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        default-jre-headless \
        fonts-dejavu-core \
        libreoffice-core \
        libreoffice-writer \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint

RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload \
        --no-dev \
        --optimize \
        --classmap-authoritative \
        --no-interaction \
    && chmod +x /usr/local/bin/docker-entrypoint \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/app/tmp/document-conversions bootstrap/cache \
    && rm -f bootstrap/cache/*.php \
    && chown -R www-data:www-data /app

USER www-data

ENTRYPOINT ["docker-entrypoint"]
CMD ["php", "artisan", "convert-docx-to-pdf", "--no-interaction"]
