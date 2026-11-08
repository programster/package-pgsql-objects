<?php

/*
 * Test that we can create a uuid record from the table, using our own generated uuid
 */

use Programster\PgsqlObjects\CacheMode;

class TestCreateUuidObject4
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}"); // throws if it fails.
    }


    public function run() : void
    {
        $uuid = UserTable::getInstance()->generateId();

        $userDetails = [
            'id' => $uuid,
            'email' => 'user1@gmail.com',
            'name' => 'user1',
        ];

        $userRecord = UserTable::getInstance()->insert($userDetails);
        $userRecord->save();


        $loadedUserRecords = UserTable::getInstance()->loadAll(CacheMode::UPDATE_CACHE);

        if (count($loadedUserRecords) !== 1)
        {
            throw new \Exception("Did not have the expected number of user records");
        }

        /* @var $loadedUserRecord UserRecord */
        $loadedUserRecord = $loadedUserRecords[0];

        if ($loadedUserRecord->getName() !== 'user1')
        {
            throw new \Exception("User did not have expected name.");
        }

        if ($loadedUserRecord->getEmail() !== 'user1@gmail.com')
        {
            throw new \Exception("User did not have expected email.");
        }


        if ($loadedUserRecord->getId() !== $uuid)
        {
            throw new \Exception("User uuid was not what was expected");
        }

        # test table delete all.
        UserTable::getInstance()->deleteAll();

        $loadedUserRecords = UserTable::getInstance()->loadAll(CacheMode::UPDATE_CACHE);

        if (count($loadedUserRecords) !== 0)
        {
            throw new \Exception("Did not have the expected number of user records");
        }
    }
}

