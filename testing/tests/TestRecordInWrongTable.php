<?php

/*
 * Test that a table won't cache a record of another table's class, e.g. as the record's getTableHandler() returns the
 * wrong table, whether or not its cache is enabled.
 */

use Programster\PgsqlObjects\Exceptions\ExceptionWrongTable;

class TestRecordInWrongTable
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("product")}");
    }


    public function run() : void
    {
        $product = ProductTable::getInstance()->insert(['name' => 'widget']);
        $userTable = UserTable::getInstance();

        foreach ([false, true] as $isCacheEnabled)
        {
            if ($isCacheEnabled)
            {
                $userTable->enableCache();
            }
            else
            {
                $userTable->disableCache();
            }

            $threw = false;

            try
            {
                $userTable->cacheObject($product);
            }
            catch (ExceptionWrongTable)
            {
                $threw = true;
            }

            if ($threw === false)
            {
                $state = $isCacheEnabled ? 'enabled' : 'disabled';
                throw new \Exception("UserTable cached a ProductRecord, with its cache {$state}.");
            }
        }
    }
}
