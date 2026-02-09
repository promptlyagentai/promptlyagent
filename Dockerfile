# Stage 1: Build Laravel Sail 8.4 base runtime
FROM ubuntu:24.04 AS sail-runtime

LABEL maintainer="Taylor Otwell"

ARG WWWGROUP=1000
ARG NODE_VERSION=24
ARG POSTGRES_VERSION=18
ARG MYSQL_CLIENT="mysql-client"

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80" \
    SUPERVISOR_PHP_USER="sail"

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install base dependencies and PHP 8.4 (from Sail runtime)
RUN apt-get update \
    && mkdir -p /etc/apt/keyrings \
    && apt-get install -y gnupg gosu curl ca-certificates zip unzip git supervisor sqlite3 libcap2-bin libpng-dev python3 dnsutils librsvg2-bin fswatch ffmpeg nano wget lsb-release xdg-utils \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xb8dc7e53946656efbce4c1dd71daeaab4ad4cab6' | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y php8.4-cli php8.4-dev php8.4-fpm \
       php8.4-pgsql php8.4-sqlite3 php8.4-gd php8.4-imagick \
       php8.4-curl php8.4-mongodb \
       php8.4-imap php8.4-mysql php8.4-mbstring \
       php8.4-xml php8.4-zip php8.4-bcmath php8.4-soap \
       php8.4-intl php8.4-readline \
       php8.4-ldap \
       php8.4-msgpack php8.4-igbinary php8.4-redis php8.4-swoole \
       php8.4-memcached php8.4-pcov php8.4-xdebug \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js
RUN curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_$NODE_VERSION.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y nodejs \
    && npm install -g npm \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install database clients
RUN curl -sS https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor | tee /etc/apt/keyrings/pgdg.gpg >/dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt noble-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y $MYSQL_CLIENT \
    && apt-get install -y postgresql-client-$POSTGRES_VERSION \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable PHP capabilities
RUN setcap "cap_net_bind_service=+ep" /usr/bin/php8.4

# Remove ubuntu user to avoid UID conflicts, then create sail user with UID 1000
# Using UID 1000 to match Kubernetes securityContext and avoid permission issues
RUN userdel -r ubuntu
RUN groupadd --force -g $WWWGROUP sail
RUN useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1000 sail
RUN git config --global --add safe.directory /var/www/html

# Stage 2: PromptlyAgent customizations
FROM sail-runtime

LABEL maintainer="PromptlyAgent"

# Install Chrome dependencies for Puppeteer
RUN apt-get update && apt-get install -y \
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
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install nginx and additional packages for SVG conversion
RUN apt-get update && apt-get install -y \
    nginx \
    librsvg2-bin \
    librsvg2-2 \
    libmagickcore-6.q16-6-extra \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Python pip and mcp-proxy
RUN apt-get update && apt-get install -y python3-pip \
    && pip3 install --break-system-packages git+https://github.com/sparfenyuk/mcp-proxy \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable OPcache for CLI
RUN echo 'opcache.enable_cli=1' >> /etc/php/8.4/cli/php.ini \
    && echo 'opcache.file_cache=/tmp/opcache' >> /etc/php/8.4/cli/php.ini \
    && echo 'opcache.file_cache_only=0' >> /etc/php/8.4/cli/php.ini \
    && echo 'opcache.validate_timestamps=1' >> /etc/php/8.4/cli/php.ini \
    && echo 'opcache.revalidate_freq=2' >> /etc/php/8.4/cli/php.ini \
    && mkdir -p /tmp/opcache \
    && chmod 777 /tmp/opcache

# Copy nginx, php-fpm, and supervisor configuration
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/laravel.conf /etc/nginx/sites-available/default
COPY docker/php-fpm/www.conf /etc/php/8.4/fpm/pool.d/www.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy application files
COPY --chown=sail:sail . /var/www/html

# Set proper file permissions
RUN find /var/www/html/app -type f -exec chmod 644 {} \; 2>/dev/null || true \
    && find /var/www/html/resources -type f -exec chmod 644 {} \; 2>/dev/null || true \
    && find /var/www/html/config -type f -exec chmod 644 {} \; 2>/dev/null || true \
    && find /var/www/html/routes -type f -exec chmod 644 {} \; 2>/dev/null || true \
    && find /var/www/html/public -type f -exec chmod 644 {} \; 2>/dev/null || true \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Copy and set permissions for startup script
COPY docker/startup/start-supervisor.sh /usr/local/bin/start-supervisor.sh
RUN chmod +x /usr/local/bin/start-supervisor.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/start-supervisor.sh"]
