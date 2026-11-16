# Testing

These are the tests for this package itself. They're only in the repository, 
not in the package Composer installs.

The package is tested against every supported major version of PostgreSQL 
(14 to 18). To run the tests against all of them, each in a throwaway docker 
container that is removed afterwards, run `composer install`, and then:

```bash
testing/run-all-versions.sh
```

Or pass the versions to test, e.g. `testing/run-all-versions.sh 16 18`. It 
needs docker and bash (on Windows, Git Bash or WSL), and uses ports 5454 to 
5458 (5440 plus the version). It then runs PHPStan (see below), and fails if 
either the tests or PHPStan do.


## PHPStan

PHPStan checks the code in `src/` and `testing/` without running it. It's run 
by `testing/run-all-versions.sh`, or on its own with:

```bash
vendor/bin/phpstan
```

Its settings are in `phpstan.neon.dist`. It runs at level 5, the highest the 
code passes, as level 6 and up mostly ask for the value types of the arrays 
(e.g. rows and where pairs) that the public methods take and return. It 
analyses the code as PHP 8.2 to 8.5, whichever version of PHP runs it. The 
only errors it ignores come from the closures that reach another class's 
protected or private members, by running in that object's scope, which PHPStan 
can't follow, and two tests that check what PHPStan already knows: that a 
method declared to always throw really does, and a slow test that is switched 
off by a constant.


## GitHub Actions

The tests also run on GitHub Actions, for pushes to `main` and `5.x` (where 
5.0 is developed, before being merged into `main`), every tag, and pull 
requests into either. The badges at the top of the [README](../README.md) show 
the latest results on `main`:

- **Linux** (`.github/workflows/tests.yml`): runs `testing/run-all-versions.sh` 
  (the tests, then PHPStan) on PHP 8.2, 8.3, 8.4 and 8.5.
- **macOS and Windows** (`tests-macos.yml` and `tests-windows.yml`, which share 
  `tests-native.yml`): GitHub's runners for these can't run the Linux 
  PostgreSQL images, so each supported version of PostgreSQL is installed on 
  the runner directly, and tested on PHP 8.5.
- **WSL** (`tests-wsl.yml`): PHP 8.3 and PostgreSQL 16 (Ubuntu 24.04's own), 
  both in WSL 2.

Each run also lists its results and any failed tests as annotations on the 
run's summary page. They can be run by hand on any branch with "Run workflow" 
on the repository's Actions tab.


## Against a single database

To run the tests against a single database instead, they need one that they 
are free to empty, as they create tables and truncate them. On PostgreSQL 
older than 18, the test table's virtual generated column is created as a 
stored one instead, and on PostgreSQL older than 17, the tests check that 
saving new objects without IDs in one go is rejected. You can run a throwaway 
database through docker with:

```bash
docker run -d \
  --name pgsql-objects-test \
  -e POSTGRES_USER=test \
  -e POSTGRES_PASSWORD=test \
  -e POSTGRES_DB=pgsql_objects_test \
  -p 127.0.0.1:5433:5432 \
  postgres
```

Then fill in your PostgreSQL connection details (including `DB_PORT`, which is 
`5433` for the container above) into the `/testing/settings.php.tmpl` file and 
rename it to `/testing/settings.php`. Any of them can also be set with an 
environment variable of the same name prefixed with `TEST_`, e.g. 
`TEST_DB_PORT=5433`, which takes precedence over `settings.php`.

Then run the tests with:

```bash
php testing/main.php
```
