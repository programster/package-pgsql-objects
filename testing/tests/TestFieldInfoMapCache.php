<?php

/*
 * Test that a table object only fetches its column info from the database once, and keeps it from then on, so that
 * loading rows doesn't need an extra catalog query every time.
 */

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractTable;
use Programster\PgsqlObjects\AbstractUuidTable;
use Programster\PgsqlObjects\Exceptions\ExceptionTableNotFound;

class TestFieldInfoMapCache
{
    public function __construct()
    {
        ConnectionHandler::getDb()->query('DROP TABLE IF EXISTS "field_info_cache_test"');
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query('CREATE TABLE "field_info_cache_test" ("id" uuid NOT NULL, "reading" double precision NOT NULL)');

        try
        {
            $table = $this->createTable();
            $firstFieldInfoMap = $this->getFieldInfoMap($table);

            // with the table gone, only a kept copy of the column info can be returned.
            $db->query('DROP TABLE "field_info_cache_test"');

            if ($this->getFieldInfoMap($table) !== $firstFieldInfoMap)
            {
                throw new \Exception("getFieldInfoMap() did not return the column info it fetched the first time.");
            }

            $this->assertNotFoundForNewTable();
        }
        finally
        {
            $db->query('DROP TABLE IF EXISTS "field_info_cache_test"');
        }
    }


    /**
     * Check that a new table object, which hasn't fetched the column info yet, can't find the dropped table. This
     * shows the column info above really came from the kept copy, rather than the database.
     */
    private function assertNotFoundForNewTable() : void
    {
        $threw = false;

        try
        {
            $this->getFieldInfoMap($this->createTable());
        }
        catch (ExceptionTableNotFound)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("A new table object found the column info of a table that was dropped.");
        }
    }


    /**
     * Call the table's protected getFieldInfoMap(), from within the table's scope.
     */
    private function getFieldInfoMap(AbstractTable $table) : array
    {
        $getFieldInfoMap = function () : array { return $this->getFieldInfoMap(); };
        return $getFieldInfoMap->call($table);
    }


    /**
     * Create a new table object for the test table, rather than using a singleton, so each starts with no kept copy.
     */
    private function createTable() : AbstractTable
    {
        $table = new class extends AbstractUuidTable {
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
                return 'SampleRecord';
            }


            public function getTableName() : string
            {
                return 'field_info_cache_test';
            }
        };

        return $table;
    }
}
