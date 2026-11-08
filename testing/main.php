<?php

// settings.php is optional when every connection detail comes from a TEST_ environment variable (see
// ConnectionHandler), as it does when run-all-versions.sh runs the tests.
if (file_exists(__DIR__ . '/settings.php'))
{
    require_once(__DIR__ . '/settings.php');
}

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
    $tableClassNames[] = basename($tableFile, '.php');
}

foreach ($testFiles as $testFile)
{
    $baseName = basename($testFile);
    $testName = str_replace(".php", "", $baseName);
    $boldGreen = "\033[1;32m";
    $boldRed = "\033[1;31m";
    $reset = "\033[0m";
    $boldCyan = "\033[1;36m";
    $coloredPassedMessage = "{$boldGreen}PASSED{$reset}";
    $coloredFailedMessage = "{$boldRed}FAILED{$reset}";

    ob_start();

    try
    {
        $test = new $testName();
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