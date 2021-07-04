<?php

/*
 * Test that we can create a user object when we havent provided the id
 * (it automatically gets created for us)
 */

class TestCreateUser1
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $query = "TRUNCATE \"user\"";
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
        $userRecord->save();
        $loadedUserRecords = UserTable::getInstance()->loadAll();

        if (count($loadedUserRecords) !== 1)
        {
            throw new \Exception("Did not have the expected number of user records after insertion.");
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
            throw new \Exception("User ID was null");
        }
    }
}

