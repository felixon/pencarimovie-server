# syntax=docker/dockerfile:1
# Render-friendly Docker image using the official FrankenPHP runtime.

FROM dunglas/frankenphp:1-php8.2-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    procps \
    unzip \
    libcap2-bin \
    && rm -rf /var/lib/apt/lists/* \
    && setcap -r /usr/local/bin/frankenphp || true

WORKDIR /app

# Install Composer for the source checkout.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy the source checkout. storage is intentionally mounted separately by Render.
COPY . /app/

# Install PHP dependencies from the clean checkout.
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# The upstream runtime expects these paths to exist.
RUN mkdir -p /app/bin /app/storage \
    && printf '%s\n' '#!/usr/bin/env sh' 'exec php "$@"' > /app/bin/php \
    && chmod +x /app/bin/php \
    && if [ -f /app/bin/php.ini.unix ]; then cp /app/bin/php.ini.unix /app/bin/php.ini; fi

ENV PATH="/app/bin:$PATH"
ENV PHP_BINDIR="/app/bin"
ENV PHPRC="/app/bin/php.ini"

EXPOSE 8088
VOLUME ["/app/storage"]

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
