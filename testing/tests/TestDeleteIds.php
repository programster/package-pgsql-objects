<?php

/*
 * Test that deleteIds() deletes only the rows with the given IDs, and removes them from the cache.
 */

class TestDeleteIds
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        UserTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $user1 = UserTable::getInstance()->create(['name' => 'user1', 'email' => 'user1@gmail.com']);
        $user2 = UserTable::getInstance()->create(['name' => 'user2', 'email' => 'user2@gmail.com']);
        $user3 = UserTable::getInstance()->create(['name' => 'user3', 'email' => 'user3@gmail.com']);

        UserTable::getInstance()->deleteIds([$user1->getId(), $user2->getId()]);

        // bypass the cache so we see what is actually in the database.
        $remainingUsers = UserTable::getInstance()->loadIds(
            [$user1->getId(), $user2->getId(), $user3->getId()],
            false
        );

        if (count($remainingUsers) !== 1 || !isset($remainingUsers[$user3->getId()]))
        {
            throw new \Exception("deleteIds() did not delete exactly the requested rows.");
        }

        $deletedUserStillCached = true;

        try
        {
            UserTable::getInstance()->load($user1->getId());
        }
        catch (\Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException)
        {
            $deletedUserStillCached = false;
        }

        if ($deletedUserStillCached)
        {
            throw new \Exception("deleteIds() did not remove the deleted object from the cache.");
        }
    }
}
