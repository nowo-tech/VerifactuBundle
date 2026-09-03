# FrankenPHP (Dunglas) - PHP 8.5 Alpine
# https://frankenphp.dev/docs/docker/
FROM dunglas/frankenphp:1-php8.5-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    unzip \
    bash

# Install PHP extensions
RUN install-php-extensions intl zip pdo_sqlite pdo_mysql gd

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure git safe directory (for Composer path repo when bundle is mounted)
RUN git config --global --add safe.directory /app \
    && git config --global --add safe.directory /var/verifactu-bundle

WORKDIR /app

# Custom Caddyfile: HTTP on port 80 (no HTTPS). Mode selected via FRANKENPHP_MODE (REQ-DEMO-010).
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile.dev
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
ENV SERVER_NAME=:80

ENTRYPOINT ["docker-entrypoint.sh"]
