<?php

/*
 * Test that we can create a uuid record with providing our own uuid
 */

class TestCreateUuidObject2
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
        $uuid = Programster\PgsqlObjects\Utils::generateUuid();

        print "creating user with uuid: {$uuid}" . PHP_EOL;
        $userDetails = array(
            'id' => $uuid,
            'email' => 'user1@gmail.com',
            'name' => 'user1',
        );

        $userRecord = UserRecord::createNewFromArray($userDetails);
        $userRecord->save();
        print "user record after saving: " . $userRecord->getId() . PHP_EOL;

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

        $loadedUserRecords = UserTable::getInstance()->loadAll();

        if (count($loadedUserRecords) !== 0)
        {
            throw new \Exception("Did not have the expected number of user records");
        }
    }
}

