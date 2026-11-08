# PHP 8.2 Alpine for development and tests
FROM php:8.2-cli-alpine

RUN apk add --no-cache \
    git \
    unzip \
    bash \
    libzip-dev \
    libsodium-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev

# GD with PNG, JPEG and WebP: image setting fields (1.4) sniff, check and re-encode uploads with it.
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) zip sodium gd

# PCOV for code coverage
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
