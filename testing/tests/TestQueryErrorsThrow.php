<?php

/*
 * Test that a failing query surfaces as an ExceptionQueryError from the table methods, rather than
 * being silently ignored.
 */

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlObjects\CacheMode;

class TestQueryErrorsThrow
{
    public function __construct()
    {
    }


    public function run() : void
    {
        $table = UserTable::getInstance();

        $failingCalls = [
            'loadWhereOr' => function () use ($table) : void {
                $table->loadWhereOr(['id' => 'not-a-uuid'], CacheMode::USE_CACHE);
            },
            // a negative OFFSET is an error.
            'loadRange'   => function () use ($table) : void { $table->loadRange(-1, 1, CacheMode::USE_CACHE); },
            'updateById'  => function () use ($table) : void { $table->updateById('not-a-uuid', ['name' => 'x']); },
        ];

        // No error handler is needed to suppress pg_query()'s warning, as pgsql-lib 3.0 stops it reaching the
        // application's error handler, so this also checks that the warning doesn't get thrown instead.
        foreach ($failingCalls as $methodName => $failingCall)
        {
            $threw = false;

            try
            {
                $failingCall();
            }
            catch (ExceptionQueryError)
            {
                $threw = true;
            }

            if ($threw === false)
            {
                throw new \Exception("{$methodName}() did not throw an ExceptionQueryError for a failing query.");
            }
        }
    }
}
