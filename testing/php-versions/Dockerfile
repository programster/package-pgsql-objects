# An image for testing the package on a specific version of PHP. Used by testing/run-all-php-versions.sh.
# Build with e.g.: docker build --build-arg PHP_VERSION=8.2 --tag pgsql-objects-php8.2-test testing/php-versions
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli

# The pgsql extension, which the package needs, isn't in the official images, so it's built, with libpq's headers.
RUN apt-get update --quiet --quiet > /dev/null \
    && apt-get install --yes --quiet --quiet libpq-dev > /dev/null \
    && docker-php-ext-install pgsql > /dev/null \
    && rm --recursive --force /var/lib/apt/lists/*
