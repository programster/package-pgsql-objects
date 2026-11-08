<?php

/*
 * Test that batchInsert() inserts many rows in one query and returns the objects for them, with the values the
 * database generated (SERIAL IDs, column defaults and generated columns), or with IDs generated in code, and that
 * bad input is rejected without inserting anything.
 */

use Programster\PgsqlLib\Exceptions\ExceptionMismatchedRows;
use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData;

class TestBatchInsert
{
    public function __construct()
    {
        TicketTable::getInstance()->enableCache(); // this test checks how the cache behaves.
        $db = ConnectionHandler::getDb();
        $tableNames = ["ticket", "user", "invoice"];
        $escapedTableNames = implode(", ", $db->escapeIdentifiers($tableNames));
        $db->query("TRUNCATE {$escapedTableNames} RESTART IDENTITY");
        TicketTable::getInstance()->emptyCache();
        UserTable::getInstance()->emptyCache();
        InvoiceTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $this->testDatabaseGeneratedIds();
        $this->testCodeGeneratedIds();
        $this->testGeneratedColumns();
        $this->testRejected("an empty list", ExceptionMissingRequiredData::class, []);

        // title has no default, so a row can't leave it out when another row provides it.
        $mismatchedRows = [['title' => 'has a title'], ['status' => 'closed']];
        $this->testRejected("a row missing a required column", ExceptionMismatchedRows::class, $mismatchedRows);

        // title is NOT NULL, so the database rejects the whole insert.
        $failingRows = [['title' => 'valid'], ['title' => null]];
        $this->testRejected("a row the database rejects", ExceptionQueryError::class, $failingRows);
    }


    private function testDatabaseGeneratedIds() : void
    {
        $table = TicketTable::getInstance();

        // status has a database default, so rows can leave it out even though another row provides it.
        $rows = [
            ['title' => 'first'],
            ['title' => 'second', 'status' => 'closed'],
            ['title' => 'third'],
        ];

        $tickets = $this->indexByName($table->batchInsert($rows), 'getTitle');
        $expectedStatuses = ['first' => 'open', 'second' => 'closed', 'third' => 'open'];
        $ids = [];

        foreach ($expectedStatuses as $title => $expectedStatus)
        {
            if (!isset($tickets[$title]))
            {
                throw new \Exception("batchInsert() did not return the {$title} ticket.");
            }

            $ticket = $tickets[$title];

            if (!is_int($ticket->getId()) || $ticket->getStatus() !== $expectedStatus)
            {
                throw new \Exception("batchInsert() did not return the {$title} ticket's generated ID and status.");
            }

            // the returned objects are cached, so load() gives back the same object.
            if ($table->load($ticket->getId()) !== $ticket)
            {
                throw new \Exception("batchInsert() did not cache the {$title} ticket.");
            }

            $ids[] = $ticket->getId();
        }

        if (count(array_unique($ids)) !== 3 || count($table->loadAll(CacheMode::UPDATE_CACHE)) !== 3)
        {
            throw new \Exception("batchInsert() did not insert three tickets with different IDs.");
        }
    }


    private function testCodeGeneratedIds() : void
    {
        $providedId = UserTable::getInstance()->generateId();

        $rows = [
            ['id' => $providedId, 'name' => 'provided', 'email' => 'provided@gmail.com'],
            ['name' => 'generated', 'email' => 'generated@gmail.com'],
        ];

        $users = $this->indexByName(UserTable::getInstance()->batchInsert($rows), 'getName');

        if ($users['provided']->getId() !== $providedId)
        {
            throw new \Exception("batchInsert() did not use the ID provided for a row.");
        }

        if (!is_string($users['generated']->getId()) || $users['generated']->getId() === $providedId)
        {
            throw new \Exception("batchInsert() did not generate an ID in code for a row without one.");
        }
    }


    private function testGeneratedColumns() : void
    {
        // the total is a generated column, so the value given for it must be ignored.
        $rows = [
            ['quantity' => 2, 'unit_price_pence' => 150, 'total_pence' => 1],
            ['quantity' => 3, 'unit_price_pence' => 10],
        ];

        $invoices = InvoiceTable::getInstance()->batchInsert($rows);
        $totals = [];
        $sequenceNumbers = [];

        foreach ($invoices as $invoice)
        {
            $totals[] = $invoice->getTotalPence();
            $sequenceNumbers[] = $invoice->getSequenceNumber();
        }

        sort($totals);

        if (
               $totals !== [30, 300]
            || in_array(null, $sequenceNumbers, true)
            || count(array_unique($sequenceNumbers)) !== 2
        )
        {
            $totalsList = implode(", ", $totals);
            throw new \Exception("batchInsert() did not read back the generated columns, got totals: {$totalsList}");
        }
    }


    /**
     * Check that batchInsert() on the ticket table throws the expected exception for the rows, without inserting any.
     *
     * @param string $description - a description of the rows, for the failure message.
     *
     * @param class-string<\Throwable> $exceptionClass - the exception it should throw.
     *
     * @param array $rows - the rows to insert.
     *
     * @return void
     */
    private function testRejected(string $description, string $exceptionClass, array $rows) : void
    {
        $table = TicketTable::getInstance();
        $countBefore = count($table->loadAll(CacheMode::UPDATE_CACHE));
        $threw = false;

        try
        {
            $table->batchInsert($rows);
        }
        catch (\Throwable $exception)
        {
            if (!($exception instanceof $exceptionClass))
            {
                throw $exception;
            }

            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("batchInsert() did not throw a {$exceptionClass} for {$description}.");
        }

        if (count($table->loadAll(CacheMode::UPDATE_CACHE)) !== $countBefore)
        {
            throw new \Exception("batchInsert() inserted rows for {$description}, instead of none.");
        }
    }


    /**
     * Index the objects by the value one of their getters returns, as batchInsert() doesn't promise an order.
     *
     * @param array $objects - the objects.
     *
     * @param string $getterName - the name of the getter, e.g. getTitle.
     *
     * @return array - the objects, indexed by the getter's value.
     */
    private function indexByName(array $objects, string $getterName) : array
    {
        $indexed = [];

        foreach ($objects as $object)
        {
            $indexed[$object->$getterName()] = $object;
        }

        return $indexed;
    }
}
