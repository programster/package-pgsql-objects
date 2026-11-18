<?php

/*
 * Test that batchInsert() sends its values as parameters, and splits a batch that needs more than PostgreSQL's 65,535
 * parameters into several queries, which are all or nothing together: a failure in a later query leaves none of the
 * rows inserted (or cached), whether or not a transaction was already open, and an open transaction is left usable,
 * and isn't committed. A large value isn't copied by escaping it into the query.
 */

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;

class TestBatchInsertChunks
{
    // Two parameters a row (the ID and the body), so this needs two queries.
    private const ROW_COUNT = 40000;


    public function __construct()
    {
        $this->emptyTable();
    }


    public function run() : void
    {
        $table = DocumentTable::getInstance();
        $db = ConnectionHandler::getDb();
        $body = "It's a \\ backslash, a \"quote\" and \$1.";

        // A batch that needs two queries is inserted in full.
        $objects = $table->batchInsert($this->createRows($body));
        $this->assertCount(self::ROW_COUNT, 'after inserting a batch that needs two queries');

        if (count($objects) !== self::ROW_COUNT || $objects[self::ROW_COUNT - 1]->getBody() !== $body)
        {
            throw new \Exception("batchInsert() didn't return every record, with its value as it was.");
        }

        // A failure in the second query leaves none of the rows inserted, nor cached.
        $this->emptyTable();
        $rows = $this->createRowsWithDuplicateId($body);
        $this->assertFails($rows, 'outside a transaction');
        $this->assertCount(0, 'after the second query failed outside a transaction');
        $this->assertNotCached((string)$rows[0]['id']);

        // Inside an open transaction, the failure only undoes the batch, and the transaction can carry on.
        $db->query('BEGIN');
        $table->insert(['body' => 'kept']);
        $this->assertFails($this->createRowsWithDuplicateId($body), 'inside a transaction');
        $db->query('COMMIT');
        $this->assertCount(1, 'after the second query failed inside a transaction, which was then committed');

        // A batch inside an open transaction is left for the caller to commit or roll back.
        $this->emptyTable();
        $db->query('BEGIN');
        $table->batchInsert($this->createRows($body));
        $db->query('ROLLBACK');
        $this->assertCount(0, 'after a batch inside a transaction that was then rolled back');

        // A large value isn't copied into the query.
        $this->emptyTable();
        $value = str_repeat("{$body} ", intdiv(8 * 1024 * 1024, strlen($body) + 1));

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $table->batchInsert([['body' => $value]]);
        $ratio = round((memory_get_peak_usage() - $before) / strlen($value), 2);

        // About 1 (the row the database returns), and about 3 when the value was escaped into the query.
        if ($ratio > 2)
        {
            throw new \Exception("Batch inserting the value used {$ratio} times its size in memory, so it was copied.");
        }
    }


    /**
     * @return list<array<string, string>>
     */
    private function createRows(string $body) : array
    {
        $rows = [];

        for ($i = 0; $i < self::ROW_COUNT; $i++)
        {
            $rows[] = ['id' => DocumentTable::getInstance()->generateId(), 'body' => $body];
        }

        return $rows;
    }


    /**
     * Create the rows, with the last (which is in the second query) having the first's ID.
     * @return list<array<string, string>>
     */
    private function createRowsWithDuplicateId(string $body) : array
    {
        $rows = $this->createRows($body);
        $rows[self::ROW_COUNT - 1]['id'] = $rows[0]['id'];
        return $rows;
    }


    /**
     * @param list<array<string, string>> $rows
     */
    private function assertFails(array $rows, string $when) : void
    {
        $isRefused = false;

        try
        {
            DocumentTable::getInstance()->batchInsert($rows);
        }
        catch (ExceptionQueryError)
        {
            $isRefused = true;
        }

        if ($isRefused === false)
        {
            throw new \Exception("A batch with a duplicate ID in its second query wasn't refused {$when}.");
        }
    }


    private function assertCount(int $expected, string $when) : void
    {
        $count = (int)pg_fetch_result(ConnectionHandler::getDb()->query('SELECT count(*) FROM "document"'), 0, 0);

        if ($count !== $expected)
        {
            throw new \Exception("There were {$count} rows, not {$expected}, {$when}.");
        }
    }


    private function assertNotCached(string $id) : void
    {
        $isMissing = false;

        try
        {
            DocumentTable::getInstance()->load($id, CacheMode::USE_CACHE);
        }
        catch (ExceptionNoSuchIdException)
        {
            $isMissing = true;
        }

        if ($isMissing === false)
        {
            throw new \Exception("A record was cached for a row that was rolled back.");
        }
    }


    private function emptyTable() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("document")}");
        DocumentTable::getInstance()->emptyCache();
    }
}
