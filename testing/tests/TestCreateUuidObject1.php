<?php

/*
 * Test that we can create a uuid object when we havent provided the uuid
 * (it automatically gets created for us)
 */

class TestCreateUuidObject1
{

    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $query = "TRUNCATE {$db->escapeIdentifier("user")}";
        $result = $db->query($query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to empty the test table.");
        }
    }


    public function run()
    {
        $userDetails = array(
            'email' => 'user1@gmail.com',
            'name' => 'user1',
        );

        $userRecord = UserRecord::createNewFromArray($userDetails);

        if ($userRecord->getId() === "" || $userRecord->getId() === null)
        {
            throw new \Exception("The created user object was not automatically given a UUID.");
        }

        // vitally important this save call is made AFTER we check for a UUID as the object
        // should have a uuid BEFORE we do anything with the database.
        $userRecord->save();

        $loadedUserRecords = UserTable::getInstance()->loadAll();

        if (count($loadedUserRecords) !== 1)
        {
            throw new \Exception("Did not have the expected number of user records after insertion");
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

        if ($loadedUserRecord->getId() === null || $loadedUserRecord->getId() === "")
        {
            throw new \Exception("User uuid was null");
        }
    }
}

