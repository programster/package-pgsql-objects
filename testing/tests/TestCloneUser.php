<?php

/*
 * Test that we can clone an existing record to create a new one in the database.
 */

use Programster\PgsqlObjects\CacheMode;

class TestCloneUser
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

        $userClone = clone($userRecord);
        $userClone->save();

        $loadedUserRecords = UserTable::getInstance()->loadAll(CacheMode::UPDATE_CACHE);

        if (count($loadedUserRecords) !== 2)
        {
            throw new \Exception("Did not have the expected number of user records after insertion.");
        }

        /* @var $loadedUserRecord UserRecord */
        $loadedUserRecord = $loadedUserRecords[1];

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
    }
}

