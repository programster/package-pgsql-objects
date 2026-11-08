<?php

/*
 * Test that we can delete a record by running the delete method().
 */

use Programster\PgsqlObjects\CacheMode;

class TestDeleteUser
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


        $loadedUserRecords = UserTable::getInstance()->loadAll(CacheMode::UPDATE_CACHE);

        if (count($loadedUserRecords) !== 1)
        {
            throw new \Exception("Did not have the expected number of user records after insertion");
        }

        /* @var $loadedUserRecord UserRecord */
        $loadedUserRecord = $loadedUserRecords[0];

        // delete the user record.
        $loadedUserRecord->delete();

        $loadedUserRecords2 = UserTable::getInstance()->loadAll(CacheMode::UPDATE_CACHE);

        if (count($loadedUserRecords2) !== 0)
        {
            throw new \Exception("Did not have the expected number of user records after deletion");
        }
    }
}

