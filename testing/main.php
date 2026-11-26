<?php

// The tests check what they expect with assert(), as well as by throwing, so refuse to run if PHP would skip the
// asserts (zend.assertions is -1 in production), rather than quietly passing them. Run with -d zend.assertions=1.
if (ini_get('zend.assertions') !== '1')
{
    fwrite(STDERR, "The tests need zend.assertions=1. Run them with: bash testing/run-all-php-versions.sh" . PHP_EOL);
    exit(1);
}

// any warning, notice or deprecation fails the test that raised it, rather than passing by unnoticed, unless it was
// silenced with @ (which error_reporting() then leaves out).
$throwOnError = function (int $severity, string $message, string $file, int $line) : bool {
    if ((error_reporting() & $severity) !== 0)
    {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    // a silenced error is left to PHP's own handling, which ignores it.
    return false;
};

set_error_handler($throwOnError);

// Runs every test in tests/. Run the tests with run-all-php-versions.sh, which runs this in a container for each
// version of PHP and PostgreSQL. The macOS, Windows and WSL workflows run this directly, as their runners can't run
// Docker.
// The connection details come from TEST_ environment variables (see ConnectionHandler), which the script and the
// workflows set.
require_once(__DIR__ . '/../vendor/autoload.php');

$autoloader = new \Programster\Autoloader\Autoloader([
    __DIR__,
    __DIR__ . '/tests',
    __DIR__ . '/models',
]);

$migrationManager = new Programster\PgsqlMigrations\MigrationManager(
    __DIR__ . '/migrations',
    ConnectionHandler::getDb()->getResource()
);

$migrationManager->migrate();
print "Testing against PostgreSQL " . pg_version(ConnectionHandler::getDb()->getResource())['server'] . PHP_EOL;

$testFiles = glob(__DIR__ . '/tests/*.php');

if ($testFiles === false)
{
    throw new \RuntimeException("Failed to list the test files in " . __DIR__ . "/tests");
}

$testResults = [];
$testOutput = [];
$testExceptions = [];

// the test tables, whose caches are switched off after each test (see below).
$tableFiles = glob(__DIR__ . '/models/*Table.php');

if ($tableFiles === false)
{
    throw new \RuntimeException("Failed to list the test tables in " . __DIR__ . "/models");
}

$tableClassNames = [];

foreach ($tableFiles as $tableFile)
{
    $tableClassName = basename($tableFile, '.php');

    if (!is_subclass_of($tableClassName, \Programster\PgsqlObjects\AbstractTable::class))
    {
        throw new \RuntimeException("{$tableClassName}, in " . __DIR__ . "/models, doesn't extend AbstractTable.");
    }

    $tableClassNames[] = $tableClassName;
}

$boldGreen = "\033[1;32m";
$boldRed = "\033[1;31m";
$reset = "\033[0m";
$boldCyan = "\033[1;36m";
$coloredPassedMessage = "{$boldGreen}PASSED{$reset}";
$coloredFailedMessage = "{$boldRed}FAILED{$reset}";

foreach ($testFiles as $testFile)
{
    $baseName = basename($testFile);
    $testName = str_replace(".php", "", $baseName);

    ob_start();

    try
    {
        $test = new $testName();

        if (!method_exists($test, 'run'))
        {
            throw new \RuntimeException("{$testName} has no run() method.");
        }

        $test->run();
        $testResults[$testFile] = "passed";
        print "{$boldCyan}{$testName}{$reset}: {$coloredPassedMessage}" . PHP_EOL;
    }
    catch (\Throwable $ex) // catch errors (e.g. TypeError) as well as exceptions, so one test can't stop the run.
    {
        $testResults[$testFile] = "failed";
        $testExceptions[$testFile] = $ex;
        $failureMessage = get_class($ex) . ": " . $ex->getMessage();
        print "{$boldCyan}{$testName}{$reset}: {$coloredFailedMessage} - {$failureMessage}" . PHP_EOL;
    }

    $testOutput[$testFile] = ob_get_clean();

    // the tables are singletons, so switch off any cache a test enabled, so that it can't affect the next test.
    foreach ($tableClassNames as $tableClassName)
    {
        $tableClassName::getInstance()->disableCache();
    }
}

arsort($testResults);

foreach ($testResults as $testName => $passedOrFailed)
{
    if ($passedOrFailed === "passed")
    {
        print "{$boldCyan}{$testName}{$reset}: {$coloredPassedMessage}" . PHP_EOL;
    }
    else
    {
        $failure = $testExceptions[$testName];
        $failureMessage = get_class($failure) . ": " . $failure->getMessage();
        print "{$boldCyan}{$testName}{$reset}: {$coloredFailedMessage} - {$failureMessage}" . PHP_EOL;
    }
}

// exit with a non-zero code if any test failed, so CI/CD pipelines can detect the failure.
$numFailed = count($testExceptions);
print PHP_EOL . (count($testResults) - $numFailed) . " passed, {$numFailed} failed." . PHP_EOL;
exit(($numFailed > 0) ? 1 : 0);