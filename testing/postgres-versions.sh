#!/bin/bash

# PostgreSQL's supported major versions, which the package is tested against, see
# https://www.postgresql.org/support/versioning/. It's sourced by run-all-php-versions.sh, and read by the macOS and
# Windows workflow (.github/workflows/tests-native.yml), so the list is only kept here.
POSTGRES_VERSIONS=(14 15 16 17 18)
