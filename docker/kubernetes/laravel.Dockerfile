# Multi-stage Laravel production Dockerfile for Kubernetes
# Stage 1: Builder - Install dependencies and build assets
ARG REGISTRY=registry.local:5000
FROM ${REGISTRY}/promptlyagent/app:latest AS builder

WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies (production only)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# Copy package files for npm
COPY package*.json ./

# Install Node dependencies (need all deps including dev for building)
RUN if [ -f "package.json" ]; then \
        npm ci; \
    fi

# Copy application files
COPY . .

# Clear any dev-environment bootstrap caches (they may include dev packages like debugbar)
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# Accept VITE environment variables as build arguments
ARG VITE_REVERB_APP_ID=app-id
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https

# Set as environment variables so Vite can read them during build
ENV VITE_REVERB_APP_ID=${VITE_REVERB_APP_ID}
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
ENV VITE_REVERB_HOST=${VITE_REVERB_HOST}
ENV VITE_REVERB_PORT=${VITE_REVERB_PORT}
ENV VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

# Build frontend assets
RUN if [ -f "package.json" ]; then \
        npm run build; \
    fi

# Build docs
#RUN php artisan route:clear
#RUN php artisan route:cache
#RUN php artisan scribe:generate || echo "Warning: Scribe documentation generation failed, continuing build..."

# Pre-compile Blade views (doesn't require Redis/database)
# This creates cached views in storage/framework/views that are shipped with the image
RUN php artisan view:cache || true

# Note: config:cache is NOT run during build because build-time environment
# variables differ from runtime (no REDIS_HOST, DB_HOST, etc. during build).
# Config cache is generated at runtime by start-supervisor.sh for web pods.
# Worker pods use environment variables directly (no config cache needed).

# Stage 2: Runtime - Minimal production image
ARG REGISTRY=registry.local:5000
FROM ${REGISTRY}/promptlyagent/app:latest

ARG WWWGROUP=1000

WORKDIR /var/www/html

# Update CA certificates to fix SSL certificate verification issues
RUN apt-get update && apt-get install -y --reinstall ca-certificates openssl \
    && update-ca-certificates \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure curl to use system CA certificates for both CLI and FPM
RUN echo 'curl.cainfo="/etc/ssl/certs/ca-certificates.crt"' >> /etc/php/8.4/cli/php.ini \
    && echo 'openssl.cafile="/etc/ssl/certs/ca-certificates.crt"' >> /etc/php/8.4/cli/php.ini \
    && echo 'curl.cainfo="/etc/ssl/certs/ca-certificates.crt"' >> /etc/php/8.4/fpm/php.ini \
    && echo 'openssl.cafile="/etc/ssl/certs/ca-certificates.crt"' >> /etc/php/8.4/fpm/php.ini

# Enable OPcache for CLI to dramatically speed up artisan commands and queue workers
RUN echo 'opcache.enable_cli=1' >> /etc/php/8.4/cli/php.ini \
    && echo 'opcache.file_cache=/tmp/opcache' >> /etc/php/8.4/cli/php.ini \
    && echo 'opcache.file_cache_only=0' >> /etc/php/8.4/cli/php.ini \
    && echo 'opcache.validate_timestamps=1' >> /etc/php/8.4/cli/php.ini \
    && echo 'opcache.revalidate_freq=2' >> /etc/php/8.4/cli/php.ini \
    && mkdir -p /tmp/opcache \
    && chmod 777 /tmp/opcache

# Install runtime dependencies only
RUN apt-get update && apt-get install -y \
    # Chrome/Puppeteer dependencies (required for browser automation)
    fonts-liberation \
    libasound2t64 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libcairo2 \
    libcups2 \
    libdbus-1-3 \
    libexpat1 \
    libfontconfig1 \
    libgbm1 \
    libgcc-s1 \
    libglib2.0-0 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libpango-1.0-0 \
    libpangocairo-1.0-0 \
    libstdc++6 \
    libx11-6 \
    libx11-xcb1 \
    libxcb1 \
    libxcomposite1 \
    libxcursor1 \
    libxdamage1 \
    libxext6 \
    libxfixes3 \
    libxi6 \
    libxrandr2 \
    libxrender1 \
    libxss1 \
    libxtst6 \
    lsb-release \
    wget \
    xdg-utils \
    curl \
    nginx \
    php8.4-fpm \
    supervisor \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js (required for MCP servers)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Python pip and mcp-proxy
RUN apt-get update && apt-get install -y python3-pip \
    && pip3 install --break-system-packages git+https://github.com/sparfenyuk/mcp-proxy \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy application from builder (ownership already set by --chown flag)
# Use --chmod to set permissions during COPY (much faster than post-copy find)
COPY --from=builder --chown=sail:sail --chmod=755 /var/www/html /var/www/html

# Clear any config cache from builder stage (it won't have runtime environment variables)
# Config cache must be regenerated at runtime with proper env vars
RUN rm -f /var/www/html/bootstrap/cache/config.php

# Copy nginx and php-fpm configuration
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/laravel.conf /etc/nginx/sites-available/default
COPY docker/php-fpm/www.conf /etc/php/8.4/fpm/pool.d/www.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy and set permissions for startup script
COPY docker/startup/start-supervisor.sh /usr/local/bin/start-supervisor.sh
RUN chmod +x /usr/local/bin/start-supervisor.sh

# Fix file permissions (files should be 644, not 755)
# Only target specific directories where this matters most
# Use single find with -o (OR) for better performance
RUN find /var/www/html -type f \( \
        -path "*/app/*" -o \
        -path "*/config/*" -o \
        -path "*/database/*" -o \
        -path "*/routes/*" -o \
        -path "*/resources/*" \
    \) ! -perm 644 -exec chmod 644 {} + 2>/dev/null || true

# Create required directories and set special permissions for writable directories
# Ownership already correct from COPY --chown, just fix permissions
RUN mkdir -p /var/www/html/storage/logs \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Set user
USER sail

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s \
    CMD curl -f http://localhost/health || exit 1

# Expose port
EXPOSE 80

# Use supervisor as entrypoint
ENTRYPOINT ["/usr/local/bin/start-supervisor.sh"]
