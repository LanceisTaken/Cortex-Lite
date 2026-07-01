FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        bash \
        git \
        curl \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        autoconf \
        g++ \
        make \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        intl \
        zip \
        gd \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-network autoconf g++ make linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# NOTE: dev-only choice — FPM workers run as root. Rationale: Windows/Mac
# Docker Desktop bind-mounts show as root-owned inside the container, so the
# default www-data FPM worker cannot write to storage/ or bootstrap/cache/.
# For prod, bake the code into the image and revert to a non-root user.
RUN sed -i 's/^user = www-data/user = root/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^group = www-data/group = root/' /usr/local/etc/php-fpm.d/www.conf

EXPOSE 9000
CMD ["php-fpm", "--allow-to-run-as-root"]
