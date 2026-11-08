<?php

/*
 * Test that we can create a user object when we havent provided the ID
 * (it automatically gets created for us)
 */

use Programster\PgsqlObjects\CacheMode;

class TestLoadById
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}"); // throws if it fails.
    }


    public function run() : void
    {
        $userDetails = [
            'email' => 'user1@gmail.com',
            'name' => 'user1',
        ];

        $userRecord = UserRecord::createNewFromArray($userDetails);
        $userRecord->save();
        $userID = $userRecord->getId();

        UserTable::getInstance()->emptyCache();

        /* @var $loadedUserRecord UserRecord */
        $loadedUserRecord = UserTable::getInstance()->load($userID);

        if ($loadedUserRecord->getName() !== 'user1')
        {
            throw new \Exception("User did not have expected name.");
        }

        if ($loadedUserRecord->getEmail() !== 'user1@gmail.com')
        {
            throw new \Exception("User did not have expected email.");
        }

        if ($loadedUserRecord->getId() === null || $loadedUserRecord->getId() === "")
        {
            throw new \Exception("User ID was null");
        }

        # test deletion works.
        $userRecord->delete();


        $loadedUserRecords2 = UserTable::getInstance()->loadAll(CacheMode::UPDATE_CACHE);

        if (count($loadedUserRecords2) !== 0)
        {
            throw new \Exception("Did not have the expected number of user records");
        }
    }
}

