<?php

/*
 * Test that ExceptionQueryFailed keeps the query, message, code and previous exception it is given.
 */

use Programster\PgsqlObjects\Exceptions\ExceptionQueryFailed;

class TestExceptionQueryFailed
{
    public function __construct()
    {
    }


    public function run() : void
    {
        $previous = new \Exception("cause");
        $exception = new ExceptionQueryFailed("SELECT 1", "it failed", 42, $previous);

        if ($exception->getQuery() !== "SELECT 1")
        {
            throw new \Exception("ExceptionQueryFailed did not keep the query.");
        }

        if ($exception->getMessage() !== "it failed" || $exception->getCode() !== 42)
        {
            throw new \Exception("ExceptionQueryFailed did not keep the message and code.");
        }

        if ($exception->getPrevious() !== $previous)
        {
            throw new \Exception("ExceptionQueryFailed did not keep the previous exception.");
        }

        // the optional arguments must still be optional.
        $minimalException = new ExceptionQueryFailed("SELECT 2");

        if ($minimalException->getQuery() !== "SELECT 2" || $minimalException->getPrevious() !== null)
        {
            throw new \Exception("ExceptionQueryFailed did not handle omitted optional arguments.");
        }
    }
}
