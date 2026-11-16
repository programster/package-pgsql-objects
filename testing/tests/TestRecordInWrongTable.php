<?php

/*
 * Test that a table won't cache or batch save a record of another table's class, e.g. as the record's
 * getTableHandler() returns the wrong table, whether or not its cache is enabled, and that batchSave() rejects it
 * before saving anything.
 */

use Programster\PgsqlObjects\CacheMode;
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
        $this->testCacheObject();
        $this->testBatchSave();
    }


    private function testCacheObject() : void
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


    /**
     * batchSave() rejects another table's record before saving anything, including its own records in the batch.
     */
    private function testBatchSave() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        $userTable = UserTable::getInstance();
        $user = UserRecord::createFromArray(['name' => 'ada', 'email' => 'ada@example.com']);
        $product = ProductRecord::createFromArray(['name' => 'gadget']);
        $threw = false;

        try
        {
            $userTable->batchSave($user, $product);
        }
        catch (ExceptionWrongTable)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("UserTable's batchSave() saved a ProductRecord.");
        }

        $productNames = [];

        foreach (ProductTable::getInstance()->loadAll(CacheMode::IGNORE_CACHE) as $savedProduct)
        {
            $productNames[] = $savedProduct->getName();
        }

        if (count($userTable->loadAll(CacheMode::IGNORE_CACHE)) !== 0 || in_array('gadget', $productNames, true))
        {
            throw new \Exception("A batchSave() rejected for another table's record still saved something.");
        }
    }
}
