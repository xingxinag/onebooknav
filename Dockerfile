# OneBookNav Docker Configuration
# Multi-stage build for optimized production image

# Build stage
FROM php:8.2-fpm-alpine AS builder

# Install build dependencies
RUN apk add --no-cache \
    sqlite-dev \
    libzip-dev \
    curl-dev \
    openssl-dev \
    oniguruma-dev \
    autoconf \
    gcc \
    g++ \
    make

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_sqlite \
    zip \
    curl \
    mbstring \
    opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Production stage
FROM nginx:alpine AS production

# Install PHP-FPM and dependencies
RUN apk add --no-cache \
    php82 \
    php82-fpm \
    php82-pdo \
    php82-pdo_sqlite \
    php82-sqlite3 \
    php82-zip \
    php82-curl \
    php82-mbstring \
    php82-json \
    php82-session \
    php82-tokenizer \
    php82-openssl \
    supervisor \
    curl

# Create www-data user
RUN addgroup -g 82 -S www-data && \
    adduser -u 82 -D -S -G www-data www-data

# Configure PHP-FPM
COPY docker/php-fpm.conf /etc/php82/php-fpm.d/www.conf
COPY docker/php.ini /etc/php82/conf.d/99-custom.ini

# Configure Nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/conf.d/default.conf

# Configure Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Copy application files
COPY --chown=www-data:www-data . /var/www/html

# Create necessary directories
RUN mkdir -p \
    /var/www/html/data/logs \
    /var/www/html/data/cache \
    /var/www/html/data/backups \
    /var/www/html/data/uploads \
    /var/log/supervisor \
    /run/nginx \
    /run/php

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 777 /var/www/html/data

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:80 || exit 1

# Expose port
EXPOSE 80

# Set entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# Development stage
FROM production AS development

# Install development tools
RUN apk add --no-cache \
    git \
    vim \
    bash

# Enable XDebug for development
RUN apk add --no-cache php82-pecl-xdebug && \
    echo "zend_extension=xdebug" > /etc/php82/conf.d/xdebug.ini && \
    echo "xdebug.mode=debug" >> /etc/php82/conf.d/xdebug.ini && \
    echo "xdebug.client_host=host.docker.internal" >> /etc/php82/conf.d/xdebug.ini && \
    echo "xdebug.client_port=9003" >> /etc/php82/conf.d/xdebug.ini

# Set development environment
ENV DEBUG_MODE=true
ENV LOG_LEVEL=DEBUG