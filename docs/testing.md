# Testing

These are the tests for this package itself. They're only in the repository, 
not in the package Composer installs.

To run all of them, run `composer install`, and then:

```bash
bash testing/run-all-php-versions.sh
```

That's the only way to run them. It needs Docker and bash (on Windows, Git 
Bash or WSL), and does everything, each step in its own throwaway Docker 
container, which is removed afterwards:

1. Lints every file on every supported version of PHP (8.2 to 8.6).
2. Runs the tests on every version of PHP, against every supported major 
   version of PostgreSQL (14 to 18). Each version of PostgreSQL is started 
   once, and every version of PHP is tested against it. The tests include 
   `DocsTest`, which runs the README's examples, so the README can't drift 
   from what the package does.
3. Runs PHPStan (see below) once, on PHP 8.2.

It fails if any of them does. Linting and PHPStan run with no network at all, 
and the tests on an internal Docker network, which only connects them to the 
PostgreSQL container.

A test fails if it throws, fails an `assert()`, or raises any warning, notice 
or deprecation.

To test only some versions, pass the versions of PHP, and `--postgres=` with 
those of PostgreSQL, e.g. 
`bash testing/run-all-php-versions.sh 8.2 8.6 --postgres=14,18`. Add 
`--rebuild` to rebuild the PHP images, e.g. to get the latest patch releases. 
The images are the official `php` ones, with the `pgsql` extension added 
(`testing/php-versions/Dockerfile`).

The supported versions are each listed in one place: PHP's in 
`testing/run-all-php-versions.sh` (`SUPPORTED_VERSIONS`), and PostgreSQL's in 
`testing/postgres-versions.sh`.


## PHPStan

PHPStan checks the code in `src/` and `testing/` without running it. 
Its settings are in `phpstan.neon.dist`. It runs at level 10, the highest, and 
analyses the code as PHP 8.2 to 8.6, whichever version of PHP runs it. The 
only errors it ignores are ones it can't be told the answer to:

- The closures that reach another class's protected or private members, by 
  running in that object's scope, which PHPStan can't follow, so it can't find 
  those members, or tell what they return.
- `getInstance()`, which keeps one instance of each table class in a static 
  property they all share, so PHPStan can't see that the one it returns is of 
  the class it was called on.
- Two tests that check what PHPStan already knows: that a method declared to 
  always throw really does, and a slow test that is switched off by a constant.


## GitHub Actions

The tests also run on GitHub Actions, for pushes to `main` and `5.x` (where 
5.0 is developed, before being merged into `main`), every tag, and pull 
requests into either. The badges at the top of the [README](../README.md) show 
the latest results on `main`:

- **Tests** (`.github/workflows/tests.yml`), on Linux: runs 
  `testing/run-all-php-versions.sh`, exactly as above.
- **macOS and Windows** (`tests-macos.yml` and `tests-windows.yml`, which share 
  `tests-native.yml`): GitHub's runners for these can't run the Linux 
  PostgreSQL images, or any Docker images, so each supported version of 
  PostgreSQL (from `testing/postgres-versions.sh`) is installed on the runner 
  directly, and the tests are run on PHP 8.5 with `testing/main.php`, the 
  runner that the script itself runs in each container.
- **WSL** (`tests-wsl.yml`): PHP 8.3 and PostgreSQL 16 (Ubuntu 24.04's own), 
  both in WSL 2.

Each run also lists its results and any failed tests as annotations on the 
run's summary page (`testing/github-annotations.sh`): a notice for each 
version of PHP, with each version of PostgreSQL's results, and an error 
listing the failed tests, with the versions they failed on. They can be run by 
hand on any branch with "Run workflow" on the repository's Actions tab.
