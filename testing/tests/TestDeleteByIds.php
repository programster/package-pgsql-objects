<?php

/*
 * Test that deleteByIds() deletes only the rows with the given IDs, and removes them from the cache.
 */

use Programster\PgsqlObjects\CacheMode;

class TestDeleteByIds
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
        $user1 = UserTable::getInstance()->insert(['name' => 'user1', 'email' => 'user1@gmail.com']);
        $user2 = UserTable::getInstance()->insert(['name' => 'user2', 'email' => 'user2@gmail.com']);
        $user3 = UserTable::getInstance()->insert(['name' => 'user3', 'email' => 'user3@gmail.com']);

        UserTable::getInstance()->deleteByIds([RecordIds::get($user1), RecordIds::get($user2)]);

        // ignore the cache so we see what is actually in the database.
        $remainingUsers = UserTable::getInstance()->loadIds(
            [RecordIds::get($user1), RecordIds::get($user2), RecordIds::get($user3)],
            CacheMode::IGNORE_CACHE
        );

        if (count($remainingUsers) !== 1 || !isset($remainingUsers[$user3->getId()]))
        {
            throw new \Exception("deleteByIds() did not delete exactly the requested rows.");
        }

        $deletedUserStillCached = true;

        try
        {
            UserTable::getInstance()->load(RecordIds::get($user1));
        }
        catch (\Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException)
        {
            $deletedUserStillCached = false;
        }

        if ($deletedUserStillCached)
        {
            throw new \Exception("deleteByIds() did not remove the deleted object from the cache.");
        }
    }
}
