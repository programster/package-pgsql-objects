<?php

/*
 * Test batchSave() with new objects whose IDs the database generates (SERIAL), mixed with an existing object: it
 * saves them all in one transaction, gives each new object its own generated ID and defaults, and either saves every
 * object or none. That needs MERGE ... RETURNING (PostgreSQL 17+), so on older servers, test that such a batch is
 * rejected before anything is saved instead.
 */

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionObjectDeleted;
use Programster\PgsqlObjects\Exceptions\ExceptionUnsupportedDatabaseVersion;

class TestBatchSaveDatabaseIds
{
    public function __construct()
    {
        TicketTable::getInstance()->enableCache(); // this test checks the new objects are cached.
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("ticket")} RESTART IDENTITY");
        TicketTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        if (ConnectionHandler::getServerMajorVersion() >= 17)
        {
            $this->testMixedBatch();
            $this->testFailedBatchSavesNothing();
        }
        else
        {
            $this->testOlderServerRejectsBatch();
        }

        $this->testDeletedObjectIsRejected();
    }


    private function testMixedBatch() : void
    {
        $table = TicketTable::getInstance();
        $existing = $table->insert(['title' => 'existing']);
        $existing->setTitle('existing renamed');
        $first = TicketRecord::createNewFromArray(['title' => 'first']);
        $second = TicketRecord::createNewFromArray(['title' => 'second', 'status' => 'closed']);
        $table->batchSave([$first, $existing, $second]);

        // each new object has its own ID, from the row with its own values, and took on the status default.
        foreach (['first' => [$first, 'open'], 'second' => [$second, 'closed']] as $title => [$ticket, $status])
        {
            if (!is_int($ticket->getId()) || $ticket->getStatus() !== $status || !$ticket->isPersisted())
            {
                throw new \Exception("The {$title} ticket didn't get its generated ID and status from batchSave().");
            }

            if ($table->load($ticket->getId(), CacheMode::IGNORE_CACHE)->getTitle() !== $title)
            {
                throw new \Exception("The {$title} ticket was given the ID of a different row.");
            }

            if ($table->load($ticket->getId()) !== $ticket)
            {
                throw new \Exception("The {$title} ticket wasn't cached after batchSave().");
            }
        }

        if ($first->getId() === $second->getId())
        {
            throw new \Exception("batchSave() gave two new tickets the same ID.");
        }

        if ($table->load($existing->getId(), CacheMode::IGNORE_CACHE)->getTitle() !== 'existing renamed')
        {
            throw new \Exception("batchSave() didn't update the existing ticket alongside the new ones.");
        }
    }


    /**
     * On PostgreSQL older than 17, a batch with a new object that has no ID throws, without saving anything, including
     * the other objects' changes, while a batch where every object has an ID still saves.
     */
    private function testOlderServerRejectsBatch() : void
    {
        $table = TicketTable::getInstance();
        $existing = $table->insert(['title' => 'unchanged']);
        $existing->setTitle('changed');
        $new = TicketRecord::createNewFromArray(['title' => 'not saved']);
        $rowCount = count($table->loadAll(CacheMode::IGNORE_CACHE));
        $threw = false;

        try
        {
            $table->batchSave([$existing, $new]);
        }
        catch (ExceptionUnsupportedDatabaseVersion)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("batchSave() didn't reject an object without an ID on an older PostgreSQL.");
        }

        $existingInDatabase = $table->load($existing->getId(), CacheMode::IGNORE_CACHE);

        if ($existingInDatabase->getTitle() !== 'unchanged')
        {
            throw new \Exception("A rejected batchSave() still saved the existing object's change.");
        }

        if ($new->getId() !== null || count($table->loadAll(CacheMode::IGNORE_CACHE)) !== $rowCount)
        {
            throw new \Exception("A rejected batchSave() still inserted rows.");
        }

        // without the new object, the batch only has objects with IDs, which every version supports.
        $table->batchSave([$existing]);

        if ($table->load($existing->getId(), CacheMode::IGNORE_CACHE)->getTitle() !== 'changed')
        {
            throw new \Exception("batchSave() didn't save a batch of objects with IDs on an older PostgreSQL.");
        }
    }


    private function testDeletedObjectIsRejected() : void
    {
        $table = TicketTable::getInstance();
        $deleted = $table->insert(['title' => 'deleted']);
        $deleted->delete();
        $new = TicketRecord::createNewFromArray(['title' => 'not saved']);
        $rowCount = count($table->loadAll(CacheMode::IGNORE_CACHE));
        $threw = false;

        try
        {
            $table->batchSave([$new, $deleted]);
        }
        catch (ExceptionObjectDeleted)
        {
            $threw = true;
        }

        if ($threw === false || $new->getId() !== null || count($table->loadAll(CacheMode::IGNORE_CACHE)) !== $rowCount)
        {
            throw new \Exception("batchSave() didn't reject a deleted object before saving anything.");
        }
    }


    private function testFailedBatchSavesNothing() : void
    {
        $table = TicketTable::getInstance();
        $existing = $table->insert(['title' => 'unchanged']);
        $existing->setTitle('changed');
        $rowCount = count($table->loadAll(CacheMode::IGNORE_CACHE));

        // title is NOT NULL, so the database rejects this ticket, which must stop the whole batch.
        $invalid = TicketRecord::createNewFromArray(['title' => null]);
        $threw = false;

        try
        {
            $table->batchSave([$existing, $invalid]);
        }
        catch (ExceptionQueryError)
        {
            $threw = true;
        }

        $existingInDatabase = $table->load($existing->getId(), CacheMode::IGNORE_CACHE);

        if ($threw === false || $existingInDatabase->getTitle() !== 'unchanged')
        {
            throw new \Exception("A batchSave() that failed still saved the existing object's change.");
        }

        if ($invalid->getId() !== null || count($table->loadAll(CacheMode::IGNORE_CACHE)) !== $rowCount)
        {
            throw new \Exception("A batchSave() that failed still inserted rows.");
        }
    }
}
