#!/bin/sh
# Runs inside a test container (see testing/run-all-php-versions.sh). The repository is mounted read-only at /src, so
# it's copied to /app first, so nothing the tests do can change it.
#
# Usage: run-in-container.sh lint     lints every PHP file in src/ and testing/
#        run-in-container.sh tests    runs the tests, against the database in the TEST_DB_ environment variables
#
# Exits with 0 if everything passed, or 1 if anything failed.

cp --recursive /src /app
failed=0

if [ "$1" = "lint" ]
then
    echo "PHP $(php -r 'echo PHP_VERSION;'): linting..."

    # Fail if there's nothing to lint (e.g. find is missing), rather than quietly linting nothing.
    files=$(find /app/src /app/testing -name "*.php")

    if [ -z "$files" ]
    then
        echo "  FAIL lint: found no PHP files to lint (is find installed?)"
        failed=1
    fi

    for file in $files
    do
        if ! output=$(php -l "$file" 2>&1)
        then
            # php -l starts its message with a blank line, and ends it with "Errors parsing <file>".
            firstLine=$(echo "$output" | grep --invert-match --regexp='^$' --regexp='^Errors parsing' | head --lines=1)
            echo "  FAIL lint: ${firstLine}"
            failed=1
        fi
    done
elif [ "$1" = "tests" ]
then
    if ! php -d zend.assertions=1 /app/testing/main.php
    then
        failed=1
    fi
else
    echo "Usage: run-in-container.sh lint|tests"
    failed=1
fi

exit $failed
