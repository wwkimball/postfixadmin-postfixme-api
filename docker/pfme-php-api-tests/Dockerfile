FROM php:8.2-cli

LABEL description="PostfixMe PHP API Unit Test Runner"
LABEL version="1.0.0"

# Install required extensions and doveadm for password hashing
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    curl \
    dovecot-core \
    && docker-php-ext-install pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/pfme-api

# Copy application files
COPY --parents composer.json config public src tests /var/www/pfme-api/
COPY docker/pfme-php-api-tests/scripts/run-phpunit-tests.sh /usr/local/bin/

# Install PHP dependencies including dev
RUN composer install --optimize-autoloader --no-interaction 2>&1

# Default command runs PHPUnit tests (all test suites: Unit and Integration)
CMD ["run-phpunit-tests.sh"]
