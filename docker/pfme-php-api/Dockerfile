# Global ARG for deployment stage selection (must be before any FROM)
ARG DEPLOYMENT_STAGE=production

FROM php:8.2-apache AS base

LABEL description="Example PostfixMe PHP API"
LABEL version="0.1.0"

ARG DEPLOYMENT_STAGE=production
ENV DEPLOYMENT_STAGE=${DEPLOYMENT_STAGE}
ENV MYSQL_HOST=database
ENV MYSQL_PORT=3306
ENV MYSQL_DATABASE=
ENV MYSQL_USER=root
ENV POSTFIXADMIN_DB_NAME=email
ENV DBSCHEMA_SETTINGS_TABLE=dbschema_settings

# Install dependencies
RUN apt-get update && apt-get install -y \
    bash \
	mariadb-client \
    dovecot-core \
    unzip \
    libzip-dev \
    dovecot-core \
    && docker-php-ext-install pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Copy the database schema and shell script helper library into the container
WORKDIR /opt/dbutils
COPY ./schema schema
COPY ./lib lib

RUN a2enmod rewrite headers env

# Configure Apache headers
COPY ./docker/pfme-php-api/config/php-headers.conf /etc/apache2/conf-available/php-headers.conf
RUN a2enconf php-headers

# Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application files from repository context
COPY ./config ./public ./src /var/www/pfme-api/

# Copy composer manifest so `composer install` can run
COPY ./composer.json /var/www/pfme-api/

# Install PHP dependencies with Composer
WORKDIR /var/www/pfme-api
RUN composer install --no-dev --optimize-autoloader --no-interaction 2>&1

ENV APACHE_DOCUMENT_ROOT=/var/www/pfme-api/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Ensure public/ exists and has the routing files
RUN mkdir -p /var/www/pfme-api/public
COPY ./public /var/www/pfme-api/public

# Set ownership so Apache can serve files
RUN chown -R www-data:www-data /var/www/pfme-api

# Intermediate stage for seeded environments - includes test seed data
FROM base AS with-seeds
# Copy processed SQL files only (not template files)
# Note: This requires that build-pre.sh has already processed templates
COPY test-data/seeds/*.sql /opt/dbutils/test-data/
COPY test-data/scripts/ /usr/local/bin/
RUN chmod +x /usr/local/bin/*.sh

# Development stage - inherits test seed data
FROM with-seeds AS development

# Lab stage - inherits test seed data
FROM with-seeds AS lab

# QA stage - inherits test seed data
FROM with-seeds AS qa

# Staging stage - no test seed data
FROM base AS staging

# Production stage - no test seed data
FROM base AS production

# Final stage selection based on DEPLOYMENT_STAGE build arg
FROM ${DEPLOYMENT_STAGE} AS final

# Entrypoint
COPY ./docker/pfme-php-api/scripts/pfme-entrypoint.sh /usr/local/bin/pfme-entrypoint.sh
RUN chmod +x /usr/local/bin/pfme-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/pfme-entrypoint.sh"]
CMD ["apache2-foreground"]
