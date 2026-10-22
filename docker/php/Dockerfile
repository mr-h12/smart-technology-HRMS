FROM php:8.4-fpm-bookworm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libpq5 libicu72 libzip4 git unzip; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath intl zip pcntl; \
    printf "\n\n\n\n" | pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove libpq-dev libicu-dev libzip-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV TZ=UTC
RUN printf 'date.timezone=UTC\nmemory_limit=512M\nupload_max_filesize=6M\npost_max_size=8M\n' > /usr/local/etc/php/conf.d/zz-app.ini

COPY zzz-pool.conf /usr/local/etc/php-fpm.d/zzz-pool.conf

WORKDIR /var/www/html
