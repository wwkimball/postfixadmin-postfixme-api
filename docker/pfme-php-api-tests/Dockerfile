FROM php:8.2-cli

LABEL maintainer="William Kimball, Jr."
LABEL description="PFME API Test Runner"

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    curl \
    && docker-php-ext-install pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/pfme-api

# Copy application under test from repository context
COPY pfme/api/ /var/www/pfme-api/

WORKDIR /var/www/pfme-api
RUN composer install --no-interaction --optimize-autoloader 2>&1 || true

CMD ["./vendor/bin/phpunit"]
