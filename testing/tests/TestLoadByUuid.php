<?php

/*
 * Test that we can create a uuid object when we havent provided the uuid
 * (it automatically gets created for us)
 */

class TestLoadByUuid
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
        $id = Programster\PgsqlObjects\Utils::generateUuid();

        $userDetails = array(
            'id' => $id,
            'email' => 'user1@gmail.com',
            'name' => 'user1',
        );

        $userRecord = UserRecord::createNewFromArray($userDetails);
        $userRecord->save();

        UserTable::getInstance()->emptyCache();

        /* @var $loadedUserRecord UserRecord */
        $loadedUserRecord = UserTable::getInstance()->load($id);


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

        # test deletion works.
        $userRecord->delete();


        $loadedUserRecords2 = UserTable::getInstance()->loadAll();

        if (count($loadedUserRecords2) !== 0)
        {
            throw new \Exception("Did not have the expected number of user records");
        }
    }
}

