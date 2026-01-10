FROM php:8.3-fpm

WORKDIR /app

ARG DEBIAN_FRONTEND=noninteractive

# System deps
RUN apt-get update && apt-get install -y --no-install-recommends \
    apt-utils \
    git \
    supervisor \
    libpq-dev \
    libzip-dev \
    unzip \
    curl \
    procps \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-configure pcntl \
    && docker-php-ext-install -j "$(nproc)" \
        pcntl \
        pdo \
        pdo_pgsql \
        zip

# OpenSwoole
RUN pecl install -f -D 'enable-openssl="no" enable-http2="no" enable-mysqlnd="no" enable-hook-curl="no"' openswoole \
    && docker-php-ext-enable openswoole

# Composer
RUN curl -sS https://getcomposer.org/installer | php \
    -- --install-dir=/usr/local/bin --filename=composer

# Node.js for watch debug
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Install chokidar for watch debug
RUN npm init -y \
    && npm install chokidar

CMD ["/usr/bin/supervisord", "-n"]
