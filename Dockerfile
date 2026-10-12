# =============================================================================
# CBTOnline — production Docker image
# PHP 8.2 + Apache, DocumentRoot at /public
# =============================================================================

FROM php:8.2-apache

# -----------------------------------------------------------------------------
# 1. System packages + PHP extensions
# -----------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libonig-dev \
        unzip \
        zip \
        git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mbstring \
        fileinfo \
        bcmath \
        gd \
        zip \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# 2. Apache modules
# -----------------------------------------------------------------------------
# Exactly one MPM must be loaded; mod_php requires mpm_prefork
RUN a2dismod mpm_event mpm_worker mpm_prefork || true \
    && a2enmod mpm_prefork \
    && a2enmod rewrite headers expires deflate

# -----------------------------------------------------------------------------
# 3. DocumentRoot → /public
# -----------------------------------------------------------------------------
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

# -----------------------------------------------------------------------------
# 4. Allow .htaccess overrides in /public, deny sensitive folders elsewhere
# -----------------------------------------------------------------------------
RUN printf '%s\n' \
    '<Directory /var/www/html/public>' \
    '    Options -Indexes -ExecCGI -MultiViews' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    '<Directory /var/www/html/app>' \
    '    Require all denied' \
    '</Directory>' \
    '<Directory /var/www/html/config>' \
    '    Require all denied' \
    '</Directory>' \
    '<Directory /var/www/html/storage>' \
    '    Require all denied' \
    '</Directory>' \
    '<Directory /var/www/html/cron>' \
    '    Require all denied' \
    '</Directory>' \
    '<Directory /var/www/html/database>' \
    '    Require all denied' \
    '</Directory>' \
    '<Directory /var/www/html/scripts>' \
    '    Require all denied' \
    '</Directory>' \
    '<FilesMatch "^\.">' \
    '    Require all denied' \
    '</FilesMatch>' \
    > /etc/apache2/conf-available/app-overrides.conf \
    && a2enconf app-overrides

# -----------------------------------------------------------------------------
# 5. PHP configuration (upload sizes, memory, timezone)
# -----------------------------------------------------------------------------
RUN printf '%s\n' \
    'upload_max_filesize = 10M' \
    'post_max_size = 12M' \
    'memory_limit = 256M' \
    'max_execution_time = 60' \
    'max_input_time = 60' \
    'display_errors = Off' \
    'display_startup_errors = Off' \
    'log_errors = On' \
    'error_log = /var/www/html/storage/logs/php-error.log' \
    'date.timezone = Africa/Lagos' \
    'session.save_path = "/var/lib/php/sessions"' \
    > /usr/local/etc/php/conf.d/app.ini

# -----------------------------------------------------------------------------
# 6. Copy the application code
# -----------------------------------------------------------------------------
WORKDIR /var/www/html

COPY . .

# -----------------------------------------------------------------------------
# 7. Permissions and directory scaffolding
# -----------------------------------------------------------------------------
RUN mkdir -p \
        storage/logs \
        storage/cache \
        storage/tmp \
        storage/uploads/documents \
        storage/uploads/proofs \
        /var/lib/php/sessions \
    && chown -R www-data:www-data /var/www/html /var/lib/php/sessions \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/lib/php/sessions \
    && find /var/www/html/storage -type d -exec chmod 755 {} \; \
    && find /var/www/html/storage -type f -exec chmod 644 {} \;

# If a .env exists in the build context it would end up here; strip it out
# since Railway supplies the variables at runtime.
RUN rm -f /var/www/html/.env

# -----------------------------------------------------------------------------
# 8. Entrypoint: substitute $PORT into Apache at container start
# -----------------------------------------------------------------------------
RUN printf '%s\n' \
    '#!/bin/sh' \
    'set -e' \
    'PORT="${PORT:-8080}"' \
    'echo "[entrypoint] Apache will listen on port ${PORT}"' \
    'sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf' \
    'sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf' \
    'exec apache2-foreground' \
    > /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
