FROM dunglas/frankenphp:latest

# Install build dependencies and libjudy
RUN apt-get update && apt-get install -y \
    build-essential \
    git \
    libjudy-dev \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Clone and compile php-judy 2.6.0 extension
RUN git clone https://github.com/orieg/php-judy.git /usr/src/php-judy \
    && cd /usr/src/php-judy \
    && phpize \
    && ./configure --with-judy=/usr \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable judy

# Prepare app workspace
WORKDIR /app

# Clone or copy sibling repositories for pure-PHP polyfill & judy-cache
RUN git clone https://github.com/orieg/judy-polyfill.git /app/judy-polyfill
COPY . /app/judy-cache

# Setup FrankenPHP demo
WORKDIR /app/demo
COPY examples/frankenphp/composer.json /app/demo/composer.json
COPY examples/frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY examples/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY examples/frankenphp/public /app/demo/public

# Install composer dependencies
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader

ENV SERVER_NAME=":8080"
ENV FRANKENPHP_CONFIG="worker /app/demo/public/worker.php 2"

WORKDIR /app/demo
EXPOSE 8080
