#!/bin/bash

# Writes GitHub Actions annotations for a test run, which (unlike the logs) can be read without logging in to GitHub:
# a notice with the results of each version of PHP tested (each version of PostgreSQL's passes and failures), a notice
# with the overall results (lint and PHPStan), and errors listing the failed tests (grouped into at most 9, as a step
# can only show 10 error annotations), or the last lines of output if it failed without listing any (e.g. a fatal
# error, or PHPStan finding errors). Works with the output of testing/run-all-php-versions.sh, and of testing/main.php,
# which the macOS, Windows and WSL workflows run directly, on one version of PHP.
# Usage: testing/github-annotations.sh <title> <file with the test output> <the tests' exit code>
#
# It uses the short forms of options, as it also runs on the macOS runner, whose BSD mktemp, sed, sort, wc and split
# have no long forms.

set -u

TITLE="$1"
OUTPUT_FILE="$2"
STATUS="$3"
WORK_DIR="$(mktemp -d)"
ESCAPE="$(printf '\033')"


# Print stdin as a single annotation message: GitHub reads %0A as a new line, so % itself must be escaped first.
encodeForAnnotation() {
    sed 's/%/%25/g' | awk '{printf "%s%%0A", $0}'
}


# Remove the colours, and shorten each test's path to just its name.
sed -e "s/${ESCAPE}\[[0-9;]*m//g" -e 's#[^ ]*/testing/tests/##' -e 's#\.php: #: #' "${OUTPUT_FILE}" \
    > "${WORK_DIR}/clean.txt"

# The results of each version of PHP, from run-all-php-versions.sh's "=== PHP x, PostgreSQL y ===" headings, each
# followed by main.php's "n passed, m failed." line, written to a file per version of PHP.
awk -v DIR="${WORK_DIR}" '
    /^=== PHP [0-9.]+, PostgreSQL [0-9]+ ===$/ { php = $3; sub(",", "", php); postgres = $5 }
    /passed, [0-9]+ failed/ && php != "" { print "PostgreSQL " postgres ": " $0 > (DIR "/php-" php ".txt") }
' "${WORK_DIR}/clean.txt"

for VERSION_FILE in "${WORK_DIR}"/php-*.txt; do
    if [ -f "${VERSION_FILE}" ]; then
        PHP_VERSION="$(basename "${VERSION_FILE}" .txt)"
        PHP_VERSION="${PHP_VERSION#php-}"
        echo "::notice title=${TITLE}, PHP ${PHP_VERSION} results::$(encodeForAnnotation < "${VERSION_FILE}")"
    fi
done

# The overall results: run-all-php-versions.sh's summary and lint failures (each run's results are in the notices
# above), or, for a run on one version of PHP, main.php's results.
if ls "${WORK_DIR}"/php-*.txt > /dev/null 2>&1; then
    RESULTS_PATTERN="^Passed: |^FAILED|FAIL lint|^PHPStan: "
else
    RESULTS_PATTERN="^Testing against PostgreSQL|passed, [0-9]+ failed|^(Passed|Failed) on PostgreSQL|^PHPStan: "
fi

RESULTS="$(grep -E "${RESULTS_PATTERN}" "${WORK_DIR}/clean.txt" | encodeForAnnotation)"

echo "::notice title=${TITLE} results::${RESULTS:-no results were found}"

# Each test is listed twice (as it runs, and in the summary), so keep one of each, with the versions it ran on.
awk '
    /^=== PHP [0-9.]+, PostgreSQL [0-9]+ ===$/ {
        php = $3
        sub(",", "", php)
        version = "PHP " php ", PostgreSQL " $5 ": "
    }
    /^Testing against PostgreSQL/ && version !~ /^PHP/ { version = "PostgreSQL " $4 ": " }
    /: FAILED - / { print version $0 }
' "${WORK_DIR}/clean.txt" | sort -u > "${WORK_DIR}/failures.txt"

TOTAL="$(wc -l < "${WORK_DIR}/failures.txt" | tr -d ' ')"

if [ "${TOTAL}" -gt 0 ]; then
    PER_GROUP="$(( (TOTAL + 8) / 9 ))"
    split -l "${PER_GROUP}" "${WORK_DIR}/failures.txt" "${WORK_DIR}/failure-group-"

    for GROUP in "${WORK_DIR}"/failure-group-*; do
        echo "::error title=${TITLE} failures::$(encodeForAnnotation < "${GROUP}")"
    done
elif [ "${STATUS}" -ne 0 ]; then
    LAST_LINES="$(tail -n 30 "${WORK_DIR}/clean.txt" | encodeForAnnotation)"
    echo "::error title=${TITLE} failed without listing a test::${LAST_LINES}"
fi

rm -rf "${WORK_DIR}"
