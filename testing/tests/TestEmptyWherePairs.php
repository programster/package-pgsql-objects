<?php

/*
 * Test that the methods that filter rows by where pairs throw an ExceptionMissingRequiredData when given none, rather
 * than loading or deleting every row in the table.
 */

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData;

class TestEmptyWherePairs
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        UserTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $table = UserTable::getInstance();
        $table->insert(['name' => 'kept', 'email' => 'kept@gmail.com']);

        // deleteWhereAnd() and deleteWhereOr() are protected, so call them from within the table's scope.
        $deleteWhereAnd = function (array $wherePairs) : void { $this->deleteWhereAnd($wherePairs); };
        $deleteWhereOr = function (array $wherePairs) : void { $this->deleteWhereOr($wherePairs); };

        $calls = [
            'loadWhereAnd'   => function () use ($table) : void { $table->loadWhereAnd([], CacheMode::USE_CACHE); },
            'loadWhereOr'    => function () use ($table) : void { $table->loadWhereOr([], CacheMode::USE_CACHE); },
            'deleteWhereAnd' => function () use ($table, $deleteWhereAnd) : void { $deleteWhereAnd->call($table, []); },
            'deleteWhereOr'  => function () use ($table, $deleteWhereOr) : void { $deleteWhereOr->call($table, []); },
        ];

        foreach ($calls as $methodName => $call)
        {
            $threw = false;

            try
            {
                $call();
            }
            catch (ExceptionMissingRequiredData $exception)
            {
                $threw = true;
                $this->assertMessage($methodName, $exception->getMessage());
            }

            if ($threw === false)
            {
                throw new \Exception("{$methodName}() did not throw ExceptionMissingRequiredData for no where pairs.");
            }
        }

        if (count($table->loadAll(CacheMode::UPDATE_CACHE)) !== 1)
        {
            throw new \Exception("A call with empty where pairs deleted rows, instead of only throwing.");
        }
    }


    /**
     * Check the exception message names the method that threw it, and the method to use to act on every row.
     */
    private function assertMessage(string $methodName, string $message) : void
    {
        $alternative = str_starts_with($methodName, 'delete') ? "deleteAll()" : "loadAll()";

        if (!str_starts_with($message, "{$methodName}()") || !str_contains($message, $alternative))
        {
            throw new \Exception("{$methodName}()'s exception didn't name it and {$alternative}, got: {$message}");
        }
    }
}
