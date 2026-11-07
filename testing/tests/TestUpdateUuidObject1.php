<?php

/*
 * Test that we can update a uuid record.
 */

class TestUpdateUuidObject1
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


    public function run() : void
    {
        $userDetails = [
            'email' => 'user1@gmail.com',
            'name' => 'user1',
        ];

        $userRecord = UserRecord::createNewFromArray($userDetails);
        $userRecord->save();


        $loadedUserRecords = UserTable::getInstance()->loadAll();

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

        $loadedUserRecord->update([
            'name' => 'another name'
        ]);

        if ($loadedUserRecord->getName() !== 'another name')
        {
            throw new \Exception("User did not have expected name.");
        }

        $loadedUserRecords2 = UserTable::getInstance()->loadAll();

        if (count($loadedUserRecords2) !== 1)
        {
            throw new \Exception("Did not have the expected number of user records");
        }

        /* @var $loadedUserRecord2 UserRecord */
        $loadedUserRecord2 = $loadedUserRecords2[0];

        if ($loadedUserRecord2->getName() !== 'another name')
        {
            throw new \Exception("User did not have expected name.");
        }
    }
}

