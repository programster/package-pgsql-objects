<?php

/*
 * Test that deleteIdsNotIn() deletes only the rows whose IDs aren't in the list, and throws an
 * ExceptionMissingRequiredData rather than deleting every row when given an empty list.
 */

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData;
use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;

class TestDeleteIdsNotIn
{
    public function __construct()
    {
        UserTable::getInstance()->enableCache(); // this test checks how the cache behaves.
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        UserTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $table = UserTable::getInstance();
        $kept = $table->insert(['name' => 'kept', 'email' => 'kept@gmail.com']);
        $deleted = $table->insert(['name' => 'deleted', 'email' => 'deleted@gmail.com']);
        $threw = false;

        try
        {
            $table->deleteIdsNotIn([]);
        }
        catch (ExceptionMissingRequiredData)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("deleteIdsNotIn() did not throw an ExceptionMissingRequiredData for an empty list.");
        }

        if (count($table->loadAll(CacheMode::UPDATE_CACHE)) !== 2)
        {
            throw new \Exception("deleteIdsNotIn() deleted rows when given an empty list, instead of only throwing.");
        }

        // loadAll() above put both objects in the cache, so the deleted one must be removed from it, or load() would
        // still return it.
        $deletedId = $deleted->getId();
        $table->deleteIdsNotIn([$kept->getId()]);
        $this->assertNotLoadable($table, $deletedId);
        $table->load($kept->getId()); // throws if the kept object was wrongly removed and is no longer in the table.
        $remaining = $table->loadAll(CacheMode::UPDATE_CACHE);

        if (count($remaining) !== 1 || $remaining[0]->getId() !== $kept->getId())
        {
            throw new \Exception("deleteIdsNotIn() did not delete exactly the rows whose IDs weren't in the list.");
        }
    }


    /**
     * Check that load(), using the cache, can't load the object with the given ID.
     */
    private function assertNotLoadable(UserTable $table, string $id) : void
    {
        $threw = false;

        try
        {
            $table->load($id);
        }
        catch (ExceptionNoSuchIdException)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("load() still returned an object that deleteIdsNotIn() deleted, from the cache.");
        }
    }
}
