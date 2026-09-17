# Development image: PHP CLI with ext-dba (cdb handler), pcov for coverage and Composer.
# Build:  docker build -t onlineconf-laravel-dev --build-arg PHP_VERSION=8.1 docker
# Run:    docker run --rm -it -v "$PWD":/app onlineconf-laravel-dev composer check
ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install dba \
    && pecl install pcov && docker-php-ext-enable pcov
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
