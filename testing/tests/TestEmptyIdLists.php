<?php

/*
 * Test that methods taking a list of IDs handle an empty list without generating invalid SQL.
 */

class TestEmptyIdLists
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
        $table->create(['name' => 'user1', 'email' => 'user1@gmail.com']);

        $table->deleteByIds([]);
        $table->deleteIds([]);

        if (count($table->loadAll()) !== 1)
        {
            throw new \Exception("Deleting an empty list of IDs deleted rows.");
        }

        if (count($table->loadIds([])) !== 0)
        {
            throw new \Exception("Loading an empty list of IDs returned objects.");
        }
    }
}
