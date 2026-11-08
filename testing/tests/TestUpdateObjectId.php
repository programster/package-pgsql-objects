<?php

/*
 * Test that a row object's ID can be changed through its update(), which changes its row's ID too, and that the cache
 * is updated to match: the old ID is no longer cached, and the object is cached under its new ID.
 */

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;

class TestUpdateObjectId
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
        $user = $table->insert(['name' => 'before', 'email' => 'before@gmail.com']);
        $oldId = $user->getId();
        $newId = $table->generateId();

        $user->update(['id' => $newId, 'name' => 'after']);

        if ($user->getId() !== $newId || $user->getName() !== 'after')
        {
            throw new \Exception("update() did not change the object's ID and name.");
        }

        // load() uses the cache by default, so this checks the cache as well as the table.
        $this->assertNotLoadable($oldId);
        $cached = $table->load($newId);

        if ($cached->getId() !== $newId || $cached->getName() !== 'after')
        {
            throw new \Exception("The cache did not have the object under its new ID, with its new values.");
        }

        $fromDatabase = $table->load($newId, CacheMode::UPDATE_CACHE);

        if ($fromDatabase->getName() !== 'after' || count($table->loadAll(CacheMode::UPDATE_CACHE)) !== 1)
        {
            throw new \Exception("update() did not change the row's ID in the table.");
        }
    }


    /**
     * Check that the old ID can't be loaded, from the cache or the table.
     */
    private function assertNotLoadable(string $oldId) : void
    {
        $threw = false;

        try
        {
            UserTable::getInstance()->load($oldId);
        }
        catch (ExceptionNoSuchIdException)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("The object could still be loaded by its old ID, after update() changed it.");
        }
    }
}
