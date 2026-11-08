<?php

/*
 * Test that running deleteAll on the table works.
 */

use Programster\PgsqlObjects\CacheMode;

class TestDeleteAll
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

        $userDetails2 = [
            'email' => 'user2@gmail.com',
            'name' => 'user2',
        ];

        $userRecord2 = UserRecord::createNewFromArray($userDetails2);
        $userRecord2->save();


        $loadedUserRecords = UserTable::getInstance()->loadAll(CacheMode::UPDATE_CACHE);

        if (count($loadedUserRecords) !== 2)
        {
            throw new \Exception("Did not have the expected number of user records");
        }

        UserTable::getInstance()->deleteAll();

        $loadedUserRecords2 = UserTable::getInstance()->loadAll(CacheMode::UPDATE_CACHE);

        if (count($loadedUserRecords2) !== 0)
        {
            throw new \Exception("Did not have the expected number of user records");
        }
    }
}

