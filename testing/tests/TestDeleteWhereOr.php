<?php

/*
 * Test that deleteWhereOr() deletes rows matching any of the given attributes, and clears the cache.
 */

class TestDeleteWhereOr
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        UserTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $table = UserTable::getInstance();
        $user1 = $table->create(['name' => 'user1', 'email' => 'user1@gmail.com']);
        $user2 = $table->create(['name' => 'user2', 'email' => 'user2@gmail.com']);
        $user3 = $table->create(['name' => "o'brien", 'email' => 'user3@gmail.com']);

        // deleteWhereOr() is protected, so call it from within the table's scope.
        $deleteWhereOr = function (array $wherePairs) {
            $this->deleteWhereOr($wherePairs);
        };

        $deleteWhereOr->call($table, ['name' => 'user1', 'email' => 'user2@gmail.com']);

        $remainingUsers = $table->loadAll();

        if (count($remainingUsers) !== 1 || $remainingUsers[0]->getId() !== $user3->getId())
        {
            throw new \Exception("deleteWhereOr() did not delete exactly the matching rows.");
        }

        // values containing quotes must be escaped exactly once to match.
        $deleteWhereOr->call($table, ['name' => "o'brien"]);

        if (count($table->loadAll()) !== 0)
        {
            throw new \Exception("deleteWhereOr() did not delete a row matched by a value containing a quote.");
        }
    }
}
