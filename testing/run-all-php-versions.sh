#!/bin/bash

# Lints every file on every supported version of PHP, runs the tests on every supported version of PHP against every
# supported version of PostgreSQL, each in its own throwaway Docker container, and then runs PHPStan once, on the
# lowest version of PHP. This catches code that only works on newer versions of PHP than the minimum this package
# supports, or on some versions of PostgreSQL.
#
# Usage:
#   bash testing/run-all-php-versions.sh                     # every version of PHP, against every PostgreSQL
#   bash testing/run-all-php-versions.sh 8.2 8.6             # only these versions of PHP
#   bash testing/run-all-php-versions.sh --postgres=16,18    # only these versions of PostgreSQL
#   bash testing/run-all-php-versions.sh --rebuild           # rebuild the PHP images, e.g. for the latest patches
#
# This is the only way to run the tests. It runs testing/main.php in each container, which the macOS, Windows and WSL
# workflows also run directly, as their runners can't run Docker.
#
# Requires Docker and bash (on Windows, Git Bash or WSL), and "composer install" to have been run, as the tests use the
# vendor/ directory. composer.json sets the platform to PHP 8.2, so that the installed dependencies work on every
# supported version.
#
# Linting and PHPStan run with no network at all. The tests run on an internal Docker network, which only connects the
# PHP containers to the PostgreSQL one, with no way out to anywhere else.
#
# Exits with 0 if everything passed, or 1 if anything failed.

set -o nounset

SUPPORTED_VERSIONS="8.2 8.3 8.4 8.5 8.6"

TESTING_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "${TESTING_DIR}")"
NETWORK_NAME="pgsql-objects-php-test"
DB_USER="test"
DB_PASSWORD="test"
DB_NAME="pgsql_objects_test"

# the supported versions of PostgreSQL, in POSTGRES_VERSIONS.
source "${TESTING_DIR}/postgres-versions.sh"

# Stop Git Bash on Windows rewriting arguments that look like paths, e.g. turning the container's /src into
# C:/Program Files/Git/src. Other systems ignore it.
export MSYS_NO_PATHCONV=1

REBUILD=0
PHP_VERSIONS=""
DB_VERSIONS="${POSTGRES_VERSIONS[*]}"

for ARGUMENT in "$@"
do
    case "${ARGUMENT}" in
        --rebuild)
            REBUILD=1
            ;;
        --postgres=*)
            DB_VERSIONS="$(echo "${ARGUMENT#--postgres=}" | tr ',' ' ')"
            ;;
        *)
            PHP_VERSIONS="${PHP_VERSIONS} ${ARGUMENT}"
            ;;
    esac
done

if [ -z "${PHP_VERSIONS}" ]
then
    PHP_VERSIONS="${SUPPORTED_VERSIONS}"
fi

if [ ! -f "${REPO_DIR}/vendor/autoload.php" ]
then
    echo "vendor/autoload.php is missing. Please run \"composer install\" first."
    exit 1
fi


# The name of the image for a version of PHP.
imageName() {
    echo "pgsql-objects-php${1}-test"
}


# The name of the container for a version of PostgreSQL.
databaseContainerName() {
    echo "pgsql-objects-php-test-pg${1}"
}


# Remove every container and the network this script creates, e.g. when it finishes or is interrupted.
cleanUp() {
    for DB_VERSION in ${DB_VERSIONS}
    do
        docker rm --force "$(databaseContainerName "${DB_VERSION}")" > /dev/null 2>&1
    done

    docker network rm "${NETWORK_NAME}" > /dev/null 2>&1
}


# Wait up to a minute for the given container's database to accept queries. Returns non-zero if it doesn't.
# pg_isready alone isn't enough, as the image's entrypoint restarts the server once it has set up the database.
waitForDatabase() {
    local CONTAINER_NAME="$1"
    local ATTEMPTS=0
    local READY=1

    while [ "${READY}" -ne 0 ] && [ "${ATTEMPTS}" -lt 60 ]
    do
        if docker exec "${CONTAINER_NAME}" psql --username="${DB_USER}" --dbname="${DB_NAME}" --host=127.0.0.1 \
            --command='SELECT 1' > /dev/null 2>&1
        then
            READY=0
        else
            ATTEMPTS="$((ATTEMPTS + 1))"
            sleep 1
        fi
    done

    return "${READY}"
}


trap cleanUp EXIT
cleanUp
FAILED_LINTS=""
FAILED_RUNS=""
PASSED_RUNS=""
BUILT_VERSIONS=""

# Build each version's image, and lint every file with it.
for PHP_VERSION in ${PHP_VERSIONS}
do
    IMAGE="$(imageName "${PHP_VERSION}")"
    echo
    echo "=== PHP ${PHP_VERSION}: lint ==="

    if [ "${REBUILD}" -eq 1 ] || ! docker image inspect "${IMAGE}" > /dev/null 2>&1
    then
        echo "building ${IMAGE}..."

        if ! docker build --quiet --pull --build-arg PHP_VERSION="${PHP_VERSION}" --tag "${IMAGE}" \
            "${TESTING_DIR}/php-versions" > /dev/null
        then
            echo "FAILED to build the image for PHP ${PHP_VERSION}"
            FAILED_LINTS="${FAILED_LINTS} ${PHP_VERSION}"
            continue
        fi
    fi

    BUILT_VERSIONS="${BUILT_VERSIONS} ${PHP_VERSION}"

    if ! docker run --rm --network none --volume "${REPO_DIR}:/src:ro" "${IMAGE}" \
        sh /src/testing/php-versions/run-in-container.sh lint
    then
        FAILED_LINTS="${FAILED_LINTS} ${PHP_VERSION}"
    fi
done

docker network create --internal "${NETWORK_NAME}" > /dev/null

# Start each version of PostgreSQL once, and run the tests against it on every version of PHP.
for DB_VERSION in ${DB_VERSIONS}
do
    DB_CONTAINER="$(databaseContainerName "${DB_VERSION}")"

    if ! docker run --detach --rm \
        --name "${DB_CONTAINER}" \
        --network "${NETWORK_NAME}" \
        --env POSTGRES_USER="${DB_USER}" \
        --env POSTGRES_PASSWORD="${DB_PASSWORD}" \
        --env POSTGRES_DB="${DB_NAME}" \
        --tmpfs /var/lib/postgresql \
        "postgres:${DB_VERSION}" > /dev/null
    then
        echo "FAILED to start a PostgreSQL ${DB_VERSION} container."
        FAILED_RUNS="${FAILED_RUNS} PostgreSQL-${DB_VERSION}"
        continue
    fi

    if ! waitForDatabase "${DB_CONTAINER}"
    then
        echo "PostgreSQL ${DB_VERSION} didn't start within a minute."
        FAILED_RUNS="${FAILED_RUNS} PostgreSQL-${DB_VERSION}"
        docker rm --force "${DB_CONTAINER}" > /dev/null 2>&1
        continue
    fi

    for PHP_VERSION in ${BUILT_VERSIONS}
    do
        echo
        echo "=== PHP ${PHP_VERSION}, PostgreSQL ${DB_VERSION} ==="

        if docker run --rm --network "${NETWORK_NAME}" --volume "${REPO_DIR}:/src:ro" \
            --env TEST_DB_HOST="${DB_CONTAINER}" \
            --env TEST_DB_PORT=5432 \
            --env TEST_DB_USER="${DB_USER}" \
            --env TEST_DB_PASSWORD="${DB_PASSWORD}" \
            --env TEST_DB_NAME="${DB_NAME}" \
            "$(imageName "${PHP_VERSION}")" sh /src/testing/php-versions/run-in-container.sh tests
        then
            PASSED_RUNS="${PASSED_RUNS} PHP-${PHP_VERSION}/PostgreSQL-${DB_VERSION}"
        else
            FAILED_RUNS="${FAILED_RUNS} PHP-${PHP_VERSION}/PostgreSQL-${DB_VERSION}"
        fi
    done

    docker rm --force "${DB_CONTAINER}" > /dev/null 2>&1
done

# Static analysis only needs to run once. It runs on the lowest version tested, as that is the most likely to differ.
LOWEST_VERSION="$(echo ${PHP_VERSIONS} | tr ' ' '\n' | sort --version-sort | head --lines=1)"
PHPSTAN_FAILED=0
echo
echo "=== PHPStan (PHP ${LOWEST_VERSION}) ==="

PHPSTAN_COMMAND="php vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G"

if ! docker run --rm --network none --volume "${REPO_DIR}:/src:ro" "$(imageName "${LOWEST_VERSION}")" \
    sh -c "cp --recursive /src /app && cd /app && ${PHPSTAN_COMMAND}"
then
    PHPSTAN_FAILED=1
fi

echo
echo "=== Results ==="
[ -z "${PASSED_RUNS}" ] || echo "Passed:${PASSED_RUNS}"
[ -z "${FAILED_RUNS}" ] || echo "FAILED:${FAILED_RUNS}"
[ -z "${FAILED_LINTS}" ] || echo "FAILED lint on PHP:${FAILED_LINTS}"

if [ "${PHPSTAN_FAILED}" -eq 1 ]
then
    echo "PHPStan: FAILED"
else
    echo "PHPStan: passed"
fi

if [ -n "${FAILED_RUNS}" ] || [ -n "${FAILED_LINTS}" ] || [ "${PHPSTAN_FAILED}" -eq 1 ]
then
    exit 1
fi
