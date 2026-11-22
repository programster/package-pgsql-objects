<?php

/*
 * Tests what OrmTransaction adds to saving and deleting records (plain queries, callbacks and retries), the combined
 * parameterised statements that it and batchSave() send, and that a rollback can't leave wrong data in the cache, or
 * lead to a save that silently does nothing.
 */

use Programster\PgsqlLib\ClosureCollection;
use Programster\PgsqlLib\Exceptions\ExceptionAfterCommitFailed;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidQuery;
use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\Query;
use Programster\PgsqlLib\QueryCollection;
use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidTransaction;
use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;
use Programster\PgsqlObjects\OrmTransaction;

class TestOrmTransactionFeatures
{
    private const BAD_QUERY = 'SELECT * FROM "no_such_table"';


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query('DROP SEQUENCE IF EXISTS "orm_attempts"; CREATE SEQUENCE "orm_attempts"');

        $tests = [
            'testPlainQueriesAndRecordsTogether',
            'testPlainQueriesOnly',
            'testFailureUndoesEverything',
            'testAliasesAndRunningOnce',
            'testCallbacks',
            'testRetries',
            'testColumnTypes',
            'testValuesAreNotCutShort',
            'testFixedLengthColumnsKeepTheirValues',
            'testDifferentColumnsAndRepeatedIds',
            'testBatchLargerThanParameterLimit',
            'testNewDatabaseIds',
            'testMissingRowFailsLoudly',
            'testCacheAfterOuterRollback',
            'testCacheAfterOuterCommit',
        ];

        try
        {
            foreach ($tests as $test)
            {
                $this->resetTables();
                $this->$test();
            }
        }
        finally
        {
            if ($db->isInTransaction())
            {
                $db->query('ROLLBACK');
            }

            AuthorTable::getInstance()->disableCache();
            $db->query('DROP SEQUENCE "orm_attempts"');
            $this->resetTables();
        }
    }


    private function testPlainQueriesAndRecordsTogether() : void
    {
        $db = ConnectionHandler::getDb();
        $first = $this->createAuthor('First');
        $second = $this->createAuthor('Second');

        $transaction = new OrmTransaction($db, ['SELECT 1 AS "value"']);
        $transaction->save($first, $second);
        $transaction->append(new Query('UPDATE "author" SET "name" = $1 WHERE "id" = $2', ['Changed', $first->getId()]));
        $results = $transaction->execute();

        $this->check("only plain queries' results are returned", count($results) === 2);
        $this->check("the first result is the first query's", pg_fetch_result($results[0], 0, 0) === '1');
        $this->check("changes run in the order added", $this->fetchAuthorName((string)$first->getId()) === 'Changed');
        $this->check("the objects are marked as saved", $first->isPersisted() && $second->isPersisted());

        $fromCollection = new OrmTransaction($db, new QueryCollection(new Query('SELECT 2')));
        $this->check("it takes a QueryCollection", pg_fetch_result($fromCollection->execute()[0], 0, 0) === '2');

        $bad = function () use ($db) : void { new OrmTransaction($db, ['COMMIT']); };
        $this->assertThrows(ExceptionInvalidQuery::class, $bad, "An invalid query was accepted.");
        $badAppend = function () use ($db) : void { (new OrmTransaction($db))->append('SELECT 1', 'ROLLBACK'); };
        $this->assertThrows(ExceptionInvalidQuery::class, $badAppend, "An invalid appended query was accepted.");
    }


    private function testPlainQueriesOnly() : void
    {
        $db = ConnectionHandler::getDb();
        $transaction = new OrmTransaction($db);
        $this->check("an empty transaction has no results", count($transaction->execute()) === 0);

        $author = $this->createAuthor('Plain');
        $author->save();
        $id = $db->escapeValue($author->getId());
        (new OrmTransaction($db, ["UPDATE \"author\" SET \"name\" = 'Raw' WHERE \"id\" = {$id}"]))->execute();
        $this->check("plain queries run with no records", $this->fetchAuthorName((string)$author->getId()) === 'Raw');
    }


    private function testFailureUndoesEverything() : void
    {
        $db = ConnectionHandler::getDb();
        $kept = $this->createAuthor('Kept');
        $kept->save();
        $new = $this->createAuthor('New');
        $kept->setName('Changed');

        $transaction = new OrmTransaction($db);
        $transaction->save($new, $kept);
        $transaction->append(self::BAD_QUERY);
        $run = function () use ($transaction) : void { $transaction->execute(); };
        $this->assertThrows(ExceptionQueryError::class, $run, "A failing query didn't throw.");

        $this->check("nothing was inserted", $this->countAuthors() === 1);
        $this->check("nothing was updated", $this->fetchAuthorName((string)$kept->getId()) === 'Kept');
        $this->check("a new object isn't marked as saved", $new->isPersisted() === false);
        $this->check("no transaction is left open", $db->isInTransaction() === false);
    }


    private function testAliasesAndRunningOnce() : void
    {
        $db = ConnectionHandler::getDb();

        $aliases = [
            function (OrmTransaction $transaction) : void { $transaction->commit(); },
            function (OrmTransaction $transaction) : void { $transaction->run(); },
            function (OrmTransaction $transaction) : void { $transaction(); },
            function (OrmTransaction $transaction) : void { $transaction->execute(); },
        ];

        foreach ($aliases as $runIt)
        {
            $author = $this->createAuthor('Alias');
            $transaction = new OrmTransaction($db);
            $transaction->save($author);
            $this->check("it counts its changes", count($transaction) === 1);
            $runIt($transaction);
            $this->check("every alias runs it", $author->isPersisted() && $transaction->hasRun());
            $again = function () use ($transaction) : void { $transaction->execute(); };
            $this->assertThrows(ExceptionInvalidTransaction::class, $again, "It ran twice.");
            $append = function () use ($transaction) : void { $transaction->append('SELECT 1'); };
            $this->assertThrows(ExceptionInvalidTransaction::class, $append, "A query was added after running.");
            $callback = function () use ($transaction) : void { $transaction->appendCallbacks(function () : void {}); };
            $this->assertThrows(ExceptionInvalidTransaction::class, $callback, "A callback was added after running.");
        }
    }


    private function testCallbacks() : void
    {
        $db = ConnectionHandler::getDb();
        $author = $this->createAuthor('Callback');
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();

        $first = function () use ($log, $author) : void {
            $log[] = 'first, saved: ' . var_export($author->isPersisted(), true);
        };

        $second = function () use ($log) : void { $log[] = 'second'; };
        $third = function () use ($log) : void { $log[] = 'third'; };
        $transaction = new OrmTransaction($db, [], [$first]);
        $transaction->save($author);
        $transaction->appendCallbacks($second, new ClosureCollection($third));
        $this->check("callbacks wait for it to run", count($log) === 0);
        $transaction->execute();
        $this->check("callbacks run in order after the objects update", $log->getArrayCopy() === ['first, saved: true', 'second', 'third']);

        $log->exchangeArray([]);
        $failing = new OrmTransaction($db, [self::BAD_QUERY], [$second]);
        $runFailing = function () use ($failing) : void { $failing->execute(); };
        $this->assertThrows(ExceptionQueryError::class, $runFailing, "A failing transaction didn't throw.");
        $this->check("callbacks don't run if it fails", count($log) === 0);

        $throws = function () : void { throw new \DomainException('failed'); };
        $saved = $this->createAuthor('Stays');
        $transaction = new OrmTransaction($db, [], [$throws, $second]);
        $transaction->save($saved);
        $run = function () use ($transaction) : void { $transaction->execute(); };
        $this->assertThrows(ExceptionAfterCommitFailed::class, $run, "A failing callback didn't throw.");
        $this->check("the commit stands if a callback fails", $saved->isPersisted());
        $this->check("the other callbacks still run", $log->getArrayCopy() === ['second']);

        $db->query('BEGIN');
        $inOpen = new OrmTransaction($db, ['SELECT 1'], [$second]);
        $runInOpen = function () use ($inOpen) : void { $inOpen->execute(); };
        $this->assertThrows(ExceptionInvalidTransaction::class, $runInOpen, "A callback ran in an open transaction.");
        $db->query('ROLLBACK');
    }


    private function testRetries() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query('ALTER SEQUENCE "orm_attempts" RESTART WITH 1');
        $author = $this->createAuthor('Retried');
        $count = 0;
        $increment = function () use (&$count) : void { $count++; };
        $transaction = new OrmTransaction($db, [], [$increment]);
        $transaction->save($author);
        $transaction->append($this->failUntilAttempt(3));
        $transaction->execute();
        $this->check("it retries by default", $this->fetchAttempts() === 3);
        $this->check("only one attempt's changes are kept", $this->countAuthors() === 1);
        $this->check("callbacks run once", $count === 1);

        $db->query('ALTER SEQUENCE "orm_attempts" RESTART WITH 1');
        $noRetries = new OrmTransaction($db, [$this->failUntilAttempt(2)], maxAttempts: 1);
        $run = function () use ($noRetries) : void { $noRetries->execute(); };
        $this->assertThrows(ExceptionQueryError::class, $run, "A clash with no retries didn't throw.");
        $this->check("maxAttempts of 1 means no retries", $this->fetchAttempts() === 1);
    }


    private function testColumnTypes() : void
    {
        $table = TypedRowTable::getInstance();

        $values = [
            'name' => 'Abc',
            'amount' => 12.5,
            'flag' => false,
            'tags' => '{red,"two words"}',
            'meta' => '{"a": 1}',
            'status' => 'active',
            'happened_at' => '2026-01-02 03:04:05+00',
            'note' => "it's; a \$1 \\ note",
        ];

        $new = TypedRowRecord::createFromArray($values);
        $nulls = TypedRowRecord::createFromArray(['name' => 'Nulls'] + array_fill_keys(array_keys(array_slice($values, 1)), null));
        $table->batchSave($new, $nulls);
        $this->assertTypedRow((string)$new->getId(), "it's; a \$1 \\ note");
        $row = $this->fetchTypedRow((string)$nulls->getId());
        $this->check("nulls are saved as NULL", $row['amount'] === null && $row['flag'] === null && $row['tags'] === null);

        // and again as an update, which casts its values differently from an insert.
        $new->setName('Xyz');
        $new->setNote('updated');
        $nulls->setNote('now set');
        $table->batchSave($new, $nulls);
        $this->assertTypedRow((string)$new->getId(), 'updated');
        $this->check("an update changes the row", $this->fetchTypedRow((string)$new->getId())['name'] === 'Xyz');
        $this->check("an update of nulls works", $this->fetchTypedRow((string)$nulls->getId())['note'] === 'now set');
    }


    /**
     * An explicit cast to varchar(5) would silently cut the value short, so the casts must leave the length out.
     * @return void
     */
    private function testValuesAreNotCutShort() : void
    {
        $table = TypedRowTable::getInstance();
        $tooLong = TypedRowRecord::createFromArray(['name' => 'Far too long'] + $this->getNullColumns());
        $insert = function () use ($table, $tooLong) : void { $table->batchSave($tooLong); };
        $this->assertThrows(ExceptionQueryError::class, $insert, "An insert cut a long value short.");

        $saved = TypedRowRecord::createFromArray(['name' => 'Short'] + $this->getNullColumns());
        $table->batchSave($saved);
        $saved->setName('Far too long');
        $update = function () use ($table, $saved) : void { $table->batchSave($saved); };
        $this->assertThrows(ExceptionQueryError::class, $update, "An update cut a long value short.");
        $this->check("the row is unchanged", $this->fetchTypedRow((string)$saved->getId())['name'] === 'Short');
    }


    /**
     * A cast to plain "character" means char(1), which would silently cut every value to its first character.
     * @return void
     */
    private function testFixedLengthColumnsKeepTheirValues() : void
    {
        $db = ConnectionHandler::getDb();
        $table = TypedRowTable::getInstance();
        $row = TypedRowRecord::createFromArray(['name' => 'Fixed', 'code' => 'abcd'] + $this->getNullColumns());
        $table->batchSave($row);
        $query = 'SELECT "code" FROM "typed_row" WHERE "id" = $1';
        $this->check("an insert keeps a char(n) value", pg_fetch_result($db->query($query, [$row->getId()]), 0, 0) === 'abcd');

        $row->setCode('wxyz');
        $table->batchSave($row);
        $this->check("an update keeps a char(n) value", pg_fetch_result($db->query($query, [$row->getId()]), 0, 0) === 'wxyz');

        $transaction = new OrmTransaction($db);
        $row->setCode('mnop');
        $transaction->save($row);
        $transaction->commit();
        $this->check("a transaction keeps a char(n) value", pg_fetch_result($db->query($query, [$row->getId()]), 0, 0) === 'mnop');

        $row->setCode('too long');
        $update = function () use ($table, $row) : void { $table->batchSave($row); };
        $this->assertThrows(ExceptionQueryError::class, $update, "A value too long for char(n) was cut short.");
    }


    private function testDifferentColumnsAndRepeatedIds() : void
    {
        $table = AuthorTable::getInstance();
        $author = $this->createAuthor('Original');
        $table->batchSave($author);
        $copyA = $table->load((string)$author->getId(), CacheMode::IGNORE_CACHE);
        $copyB = $table->load((string)$author->getId(), CacheMode::IGNORE_CACHE);
        $copyA->setName('First');
        $copyB->setName('Last');
        $table->batchSave($copyA, $copyB);
        $this->check("with the same ID twice, the last wins", $this->fetchAuthorName((string)$author->getId()) === 'Last');

        $same = $this->createAuthor('Twice');
        $saveTwice = function () use ($table, $same) : void { $table->batchSave($same, $same); };
        $this->assertThrows(ExceptionQueryError::class, $saveTwice, "Inserting the same new object twice didn't fail.");
        $this->check("nothing is left of it", $this->fetchAuthorName((string)$same->getId()) === '(no row)');
    }


    private function testBatchLargerThanParameterLimit() : void
    {
        $authors = [];

        // two parameters each, so this needs two statements.
        for ($i = 0; $i < 33000; $i++)
        {
            $authors[] = $this->createAuthor("Author {$i}");
        }

        AuthorTable::getInstance()->batchSave(...$authors);
        $this->check("every row is inserted", $this->countAuthors() === 33000);

        foreach ($authors as $i => $author)
        {
            $author->setName("Renamed {$i}");
        }

        AuthorTable::getInstance()->batchSave(...$authors);
        $result = ConnectionHandler::getDb()->query('SELECT count(*) FROM "author" WHERE "name" LIKE \'Renamed %\'');
        $this->check("every row is updated", (int)pg_fetch_result($result, 0, 0) === 33000);
    }


    private function testNewDatabaseIds() : void
    {
        if (ConnectionHandler::getServerMajorVersion() >= 17)
        {
            $author = $this->createAuthor('Parent');
            $author->save();
            $books = [];

            for ($i = 0; $i < 5; $i++)
            {
                $books[] = BookRecord::createFromArray(['author_id' => $author->getId(), 'title' => "Book {$i}"]);
            }

            BookTable::getInstance()->batchSave(...$books);

            foreach ($books as $i => $book)
            {
                $query = 'SELECT "title" FROM "book" WHERE "id" = $1';
                $title = pg_fetch_result(ConnectionHandler::getDb()->query($query, [$book->getId()]), 0, 0);
                $this->check("each new object takes on its own generated ID", $title === "Book {$i}");
            }
        }
    }


    /**
     * An update of a row that has gone must not report success, and must undo everything else in its transaction.
     * @return void
     */
    private function testMissingRowFailsLoudly() : void
    {
        $db = ConnectionHandler::getDb();
        $table = AuthorTable::getInstance();
        $gone = $this->createAuthor('Gone');
        $kept = $this->createAuthor('Kept');
        $table->batchSave($gone, $kept);
        $db->query('DELETE FROM "author" WHERE "id" = $1', [$gone->getId()]);
        $gone->setName('Gone 2');
        $kept->setName('Kept 2');

        $batch = function () use ($table, $gone, $kept) : void { $table->batchSave($kept, $gone); };
        $this->assertThrows(ExceptionNoSuchIdException::class, $batch, "batchSave() lost a missing row silently.");
        $this->check("batchSave() undoes the other rows", $this->fetchAuthorName((string)$kept->getId()) === 'Kept');

        $transaction = new OrmTransaction($db, ['INSERT INTO "author" ("id", "name") VALUES (gen_random_uuid(), \'Extra\')']);
        $transaction->save($kept, $gone);
        $commit = function () use ($transaction) : void { $transaction->execute(); };
        $this->assertThrows(ExceptionNoSuchIdException::class, $commit, "A transaction lost a missing row silently.");
        $this->check("a transaction undoes everything else", $this->countAuthors() === 1);
        $this->check("it leaves nothing open", $db->isInTransaction() === false);
    }


    private function testCacheAfterOuterRollback() : void
    {
        $db = ConnectionHandler::getDb();
        $table = AuthorTable::getInstance();
        $table->enableCache();
        $table->emptyCache();

        $existing = $this->createAuthor('Before');
        $existing->save();
        $db->query('BEGIN');
        $existing->setName('Rolled back');
        $existing->save();
        $db->query('ROLLBACK');
        $loaded = $table->load((string)$existing->getId(), CacheMode::USE_CACHE);
        $this->check("the cache doesn't keep a rolled back update", $loaded->getName() === 'Before');

        $new = $this->createAuthor('Never saved');
        $db->query('BEGIN');
        $new->save();
        $db->query('ROLLBACK');
        $missing = function () use ($table, $new) : void { $table->load((string)$new->getId(), CacheMode::USE_CACHE); };
        $this->assertThrows(ExceptionNoSuchIdException::class, $missing, "The cache kept a rolled back insert.");

        $batched = $this->createAuthor('Batched');
        $db->query('BEGIN');
        $table->batchSave($batched);
        $db->query('ROLLBACK');
        $missing = function () use ($table, $batched) : void { $table->load((string)$batched->getId(), CacheMode::USE_CACHE); };
        $this->assertThrows(ExceptionNoSuchIdException::class, $missing, "The cache kept a rolled back batch.");

        $queued = $this->createAuthor('Queued');
        $db->query('BEGIN');
        $transaction = new OrmTransaction($db);
        $transaction->save($queued);
        $transaction->execute();
        $db->query('ROLLBACK');
        $missing = function () use ($table, $queued) : void { $table->load((string)$queued->getId(), CacheMode::USE_CACHE); };
        $this->assertThrows(ExceptionNoSuchIdException::class, $missing, "The cache kept a rolled back transaction.");
        $table->disableCache();
    }


    private function testCacheAfterOuterCommit() : void
    {
        $db = ConnectionHandler::getDb();
        $table = AuthorTable::getInstance();
        $table->enableCache();
        $table->emptyCache();
        $author = $this->createAuthor('Committed');
        $db->query('BEGIN');
        $author->save();
        $db->query('COMMIT');
        $loaded = $table->load((string)$author->getId(), CacheMode::USE_CACHE);
        $this->check("the row is read after the commit", $loaded->getName() === 'Committed');
        $again = $table->load((string)$author->getId(), CacheMode::USE_CACHE);
        $this->check("it's cached again after the commit", $again === $loaded);
        $table->disableCache();
    }


    private function assertTypedRow(string $id, string $note) : void
    {
        $row = $this->fetchTypedRow($id);
        $this->check("numeric", $row['amount'] === '12.50');
        $this->check("boolean false", $row['flag'] === 'f');
        $this->check("array", $row['tags'] === '{red,"two words"}');
        $this->check("jsonb", $row['meta'] === '{"a": 1}');
        $this->check("enum", $row['status'] === 'active');
        $this->check("timestamp", $row['happened'] === '1767323045');
        $this->check("text with special characters", $row['note'] === $note);
    }


    /**
     * @return array<int|string, string|null>
     */
    private function fetchTypedRow(string $id) : array
    {
        $query =
            'SELECT "name", "amount"::text AS "amount", "flag", "tags"::text AS "tags", "meta"::text AS "meta",' .
            ' "status"::text AS "status", extract(epoch FROM "happened_at")::bigint::text AS "happened", "note"' .
            ' FROM "typed_row" WHERE "id" = $1';

        $row = pg_fetch_assoc(ConnectionHandler::getDb()->query($query, [$id]));

        if ($row === false)
        {
            throw new \Exception("There's no typed_row with ID {$id}.");
        }

        return $row;
    }


    /**
     * @return array<string, null>
     */
    private function getNullColumns() : array
    {
        return array_fill_keys(['amount', 'flag', 'tags', 'meta', 'status', 'happened_at', 'note', 'code'], null);
    }


    private function createAuthor(string $name) : AuthorRecord
    {
        return AuthorRecord::createFromArray(['name' => $name]);
    }


    private function failUntilAttempt(int $attempt) : string
    {
        return <<<SQL
        DO $$ BEGIN
            IF nextval('orm_attempts') < {$attempt} THEN
                RAISE EXCEPTION 'made-up failure' USING ERRCODE = '40001';
            END IF;
        END $$
        SQL;
    }


    private function fetchAttempts() : int
    {
        $result = ConnectionHandler::getDb()->query('SELECT last_value FROM "orm_attempts"');
        return (int)pg_fetch_result($result, 0, 0);
    }


    private function fetchAuthorName(string $id) : string
    {
        $result = ConnectionHandler::getDb()->query('SELECT "name" FROM "author" WHERE "id" = $1', [$id]);
        return (pg_num_rows($result) > 0) ? (string)pg_fetch_result($result, 0, 0) : '(no row)';
    }


    private function countAuthors() : int
    {
        $result = ConnectionHandler::getDb()->query('SELECT count(*) FROM "author"');
        return (int)pg_fetch_result($result, 0, 0);
    }


    private function check(string $label, bool $passed) : void
    {
        if ($passed === false)
        {
            throw new \Exception("Expected: {$label}");
        }
    }


    private function assertThrows(string $exceptionClass, \Closure $call, string $failureMessage) : void
    {
        $threw = false;

        try
        {
            $call();
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
            throw new \Exception($failureMessage);
        }
    }


    private function resetTables() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query('TRUNCATE "review", "book", "author", "typed_row" RESTART IDENTITY');
        AuthorTable::getInstance()->emptyCache();
        BookTable::getInstance()->emptyCache();
        TypedRowTable::getInstance()->emptyCache();
    }
}
