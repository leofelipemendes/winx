FROM php:8.4-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        zip \
        unzip \
        git \
        bash \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip bcmath pcntl opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Ajusta o usuário do PHP-FPM para o mesmo UID do host (evita problemas de permissão)
ARG UID=1000
ARG GID=1000
RUN groupmod -g ${GID} www-data \
    && usermod -u ${UID} -g ${GID} www-data

# Permite que o Tinker/PsySH grave suas configurações
RUN mkdir -p /var/www/.config/psysh \
    && chown -R www-data:www-data /var/www/.config

USER www-data