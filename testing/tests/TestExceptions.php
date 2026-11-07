<?php

/*
 * Test that every exception the package defines extends its AbstractException, so they can all be caught together,
 * and that a table class referring to a table that doesn't exist throws an ExceptionTableNotFound.
 */

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;
use Programster\PgsqlObjects\Exceptions\AbstractException;
use Programster\PgsqlObjects\Exceptions\ExceptionTableNotFound;

class TestExceptions
{
    public function __construct()
    {
    }


    public function run() : void
    {
        $this->testEveryExceptionExtendsAbstractException();
        $this->testTableNotFound();
    }


    private function testEveryExceptionExtendsAbstractException() : void
    {
        $exceptionFiles = glob(__DIR__ . '/../../src/exceptions/*.php');

        if ($exceptionFiles === false || count($exceptionFiles) === 0)
        {
            throw new \Exception("Failed to list the package's exception files.");
        }

        foreach ($exceptionFiles as $exceptionFile)
        {
            $className = 'Programster\\PgsqlObjects\\Exceptions\\' . basename($exceptionFile, '.php');

            if ($className !== AbstractException::class && !is_subclass_of($className, AbstractException::class))
            {
                throw new \Exception("{$className} does not extend the package's AbstractException.");
            }
        }
    }


    private function testTableNotFound() : void
    {
        $missingTable = new class extends AbstractUuidTable {
            public function __construct()
            {
                parent::__construct();
            }


            public function getDb() : PgSqlConnection
            {
                return ConnectionHandler::getDb();
            }


            public function getFieldsThatAllowNull() : array
            {
                return [];
            }


            public function getFieldsThatHaveDefaults() : array
            {
                return [];
            }


            public function getVirtualFields() : array
            {
                return [];
            }


            public function getObjectClassName() : string
            {
                return 'UserRecord';
            }


            public function getTableName() : string
            {
                return 'no_such_table';
            }
        };

        // getFieldInfoMap() is protected, so call it from within the table's scope.
        $getFieldInfoMap = function () : array { return $this->getFieldInfoMap(); };
        $threw = false;

        try
        {
            $getFieldInfoMap->call($missingTable);
        }
        catch (ExceptionTableNotFound)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("A table class for a missing table did not throw an ExceptionTableNotFound.");
        }
    }
}
