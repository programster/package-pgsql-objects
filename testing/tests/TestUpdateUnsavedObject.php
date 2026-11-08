<?php

/*
 * Test that update() always saves the object, inserting its row if it has never been saved, whether its ID is
 * generated in code or by the database, and that the object takes on the values the database generated.
 */

use Programster\PgsqlObjects\CacheMode;

class TestUpdateUnsavedObject
{
    public function __construct()
    {
        UserTable::getInstance()->enableCache(); // this test checks the inserted object is the cached one.
        $db = ConnectionHandler::getDb();
        $tableNames = implode(", ", $db->escapeIdentifiers(["user", "ticket"]));
        $db->query("TRUNCATE {$tableNames} RESTART IDENTITY");
        UserTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $this->testCodeGeneratedId();
        $this->testDatabaseGeneratedId();
        $this->testNewId();
    }


    private function testCodeGeneratedId() : void
    {
        $user = UserRecord::createNewFromArray(['name' => 'new', 'email' => 'new@gmail.com']);
        $user->update(['name' => 'updated']);
        $fromDatabase = UserTable::getInstance()->load($user->getId(), CacheMode::IGNORE_CACHE);

        if ($user->isPersisted() === false || $fromDatabase->getName() !== 'updated')
        {
            throw new \Exception("update() of an unsaved object with a code-generated ID didn't insert its row.");
        }

        if (UserTable::getInstance()->load($user->getId()) !== $user)
        {
            throw new \Exception("update() of an unsaved object didn't make it the cached object for its row.");
        }
    }


    private function testDatabaseGeneratedId() : void
    {
        $ticket = TicketRecord::createNewFromArray(['title' => 'new']);
        $ticket->update(['title' => 'updated']);

        // the database generates the ID and the status default, which the object should take on.
        if (!is_int($ticket->getId()) || $ticket->getStatus() !== 'open')
        {
            throw new \Exception("update() of an unsaved object didn't take on the database's generated values.");
        }

        $fromDatabase = TicketTable::getInstance()->load($ticket->getId(), CacheMode::IGNORE_CACHE);

        if ($fromDatabase->getTitle() !== 'updated')
        {
            throw new \Exception("update() of an unsaved object with a database-generated ID didn't insert its row.");
        }
    }


    private function testNewId() : void
    {
        $table = UserTable::getInstance();
        $newId = $table->generateId();
        $user = UserRecord::createNewFromArray(['name' => 'with id', 'email' => 'with-id@gmail.com']);
        $user->update(['id' => $newId]);

        if ($user->getId() !== $newId || $table->load($newId, CacheMode::IGNORE_CACHE)->getName() !== 'with id')
        {
            throw new \Exception("update() of an unsaved object didn't insert its row with the ID it was given.");
        }
    }
}
