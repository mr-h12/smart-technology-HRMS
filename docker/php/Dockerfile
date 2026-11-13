FROM node:22.22.0-bookworm-slim AS pdf-node
ARG PUPPETEER_VERSION=24.31.0
RUN PUPPETEER_SKIP_DOWNLOAD=true npm install --prefix /opt/hrms-pdf --omit=dev --no-audit --no-fund "puppeteer@${PUPPETEER_VERSION}"

FROM php:8.4-fpm-bookworm

ARG CHROMIUM_VERSION=156.0.8078.79-1~deb12u1

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libpq5 libicu72 libzip4 git unzip util-linux "chromium=${CHROMIUM_VERSION}"; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath intl zip pcntl; \
    printf "\n\n\n\n" | pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove libpq-dev libicu-dev libzip-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libpng16-16 libjpeg62-turbo; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd; \
    apt-get purge -y --auto-remove libpng-dev libjpeg62-turbo-dev; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY --from=pdf-node /usr/local/bin/node /usr/local/bin/node
COPY --from=pdf-node /opt/hrms-pdf/node_modules /opt/hrms-pdf/node_modules
COPY --chmod=0755 hrms-chromium /usr/local/bin/hrms-chromium

ENV TZ=UTC
RUN printf 'date.timezone=UTC\nmemory_limit=512M\nupload_max_filesize=12M\npost_max_size=13M\nzend.exception_ignore_args=On\n' > /usr/local/etc/php/conf.d/zz-app.ini

COPY zzz-pool.conf /usr/local/etc/php-fpm.d/zzz-pool.conf

WORKDIR /var/www/html
