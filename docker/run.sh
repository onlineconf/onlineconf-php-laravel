#!/usr/bin/env sh
# Runs a command inside the development image (PHP CLI + ext-dba + pcov + Composer) with the
# repository mounted at /app, as the current user so that files created in the mount are yours.
#
#   docker/run.sh composer install
#   docker/run.sh composer check
#   docker/run.sh vendor/bin/phpunit --filter Facade
#   docker/run.sh                       # interactive shell
#
#   PHP_VERSION=8.4 docker/run.sh composer check   # another PHP version (image is built on first use)
set -eu

PHP_VERSION="${PHP_VERSION:-8.1}"
IMAGE="onlineconf-laravel-dev:${PHP_VERSION}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    echo "building $IMAGE ..." >&2
    docker build --build-arg "PHP_VERSION=${PHP_VERSION}" -t "$IMAGE" "$ROOT/docker"
fi

TTY=""
if [ -t 0 ] && [ -t 1 ]; then
    TTY="-it"
fi

if [ $# -eq 0 ]; then
    set -- sh
fi

# shellcheck disable=SC2086
exec docker run --rm $TTY \
    -v "$ROOT":/app \
    --user "$(id -u):$(id -g)" \
    -e HOME=/tmp \
    -e COMPOSER_CACHE_DIR=/app/.composer-cache \
    "$IMAGE" "$@"
