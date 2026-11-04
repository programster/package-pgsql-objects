<?php

/*
 * Test that a failing query surfaces as an ExceptionQueryError from the table methods, rather than
 * being silently ignored.
 */

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;

class TestQueryErrorsThrow
{
    public function __construct()
    {
    }


    public function run() : void
    {
        $table = UserTable::getInstance();

        $failingCalls = array(
            'loadWhereOr' => function () use ($table) { $table->loadWhereOr(['no_such_column' => 1]); },
            'loadRange'   => function () use ($table) { $table->loadRange(-1, 1); }, // negative OFFSET is an error
            'update'      => function () use ($table) { $table->update('not-a-uuid', ['name' => 'x']); },
        );

        foreach ($failingCalls as $methodName => $failingCall)
        {
            $threw = false;

            try
            {
                // suppress the warning pg_query() emits alongside the exception.
                set_error_handler(function () { return true; });
                $failingCall();
            }
            catch (ExceptionQueryError)
            {
                $threw = true;
            }
            finally
            {
                restore_error_handler();
            }

            if ($threw === false)
            {
                throw new \Exception("{$methodName}() did not throw an ExceptionQueryError for a failing query.");
            }
        }
    }
}
