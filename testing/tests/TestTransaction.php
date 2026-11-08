<?php

/*
 * Test Transaction: saving and deleting objects of several tables in the order they were added, in one transaction,
 * that if anything fails, nothing is saved or deleted and every object is left as it was, and that it can't be misused.
 * Authors' IDs are UUIDs generated in code, and books' IDs are SERIAL, with a foreign key to their author. Reviews
 * have a DEFERRABLE foreign key to their author, to test that the transaction defers it.
 */

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionInvalidTransaction;
use Programster\PgsqlObjects\Exceptions\ExceptionObjectDeleted;
use Programster\PgsqlObjects\Exceptions\ExceptionUnsupportedDatabaseVersion;
use Programster\PgsqlObjects\Transaction;

class TestTransaction
{
    public function __construct()
    {
        // these tests check what's cached afterwards.
        AuthorTable::getInstance()->enableCache();
        BookTable::getInstance()->enableCache();
    }


    public function run() : void
    {
        $tests = [
            'testSavesAndDeletesInOrder',
            'testWrongOrderSavesNothing',
            'testFailureLeavesEverythingAsItWas',
            'testNewDatabaseIdsInSeveralGroups',
            'testInsideAnOpenTransaction',
            'testFailureInsideAnOpenTransaction',
            'testMisuseIsRejected',
            'testDeferrableConstraintsAreDeferred',
            'testDeferredConstraintFailureSavesNothing',
        ];

        foreach ($tests as $test)
        {
            $this->resetTables();
            $this->$test();
        }
    }


    /**
     * Saves new and existing objects of both tables, and deletes others, child rows before their parents.
     */
    private function testSavesAndDeletesInOrder() : void
    {
        $authorTable = AuthorTable::getInstance();
        $bookTable = BookTable::getInstance();
        $existingAuthor = $authorTable->insert(['name' => 'existing']);
        $existingBook = $bookTable->insert(['author_id' => $existingAuthor->getId(), 'title' => 'existing']);
        $doomedAuthor = $authorTable->insert(['name' => 'doomed']);
        $doomedBook = $bookTable->insert(['author_id' => $doomedAuthor->getId(), 'title' => 'doomed']);
        $newAuthor = AuthorRecord::createNewFromArray(['name' => 'new']);
        $existingAuthor->setName('renamed');
        $existingBook->setTitle('retitled');

        // the book's existing ID means this works on every version of PostgreSQL.
        $existingBook->setAuthorId($newAuthor->getId());

        $transaction = new Transaction();
        $transaction->save($existingAuthor);
        $transaction->save($newAuthor);
        $transaction->save($existingBook); // after its new author, as it references it.
        $transaction->delete($doomedBook); // before its author, which it references.
        $transaction->delete($doomedAuthor);
        $transaction->commit();

        $authorNames = $this->getAuthorNamesInDatabase();
        $bookInDatabase = $bookTable->load($existingBook->getId(), CacheMode::IGNORE_CACHE);

        if ($authorNames !== ['new', 'renamed'])
        {
            throw new \Exception("The transaction didn't save and delete the authors: " . implode(", ", $authorNames));
        }

        if ($bookInDatabase->getTitle() !== 'retitled' || $bookInDatabase->getAuthorId() !== $newAuthor->getId())
        {
            throw new \Exception("The transaction didn't save the existing book's changes.");
        }

        if (count($bookTable->loadAll(CacheMode::IGNORE_CACHE)) !== 1)
        {
            throw new \Exception("The transaction didn't delete the doomed book.");
        }

        if (!$newAuthor->isSavedInDatabase() || $authorTable->load($newAuthor->getId()) !== $newAuthor)
        {
            throw new \Exception("The new author wasn't marked as saved and cached after the commit.");
        }

        if (!$doomedBook->isDeleted() || !$doomedAuthor->isDeleted() || $doomedAuthor->isSavedInDatabase())
        {
            throw new \Exception("The deleted objects weren't marked as deleted after the commit.");
        }
    }


    /**
     * A book saved before the new author it references breaks the foreign key, so nothing is saved, and in the right
     * order, the same objects then save.
     */
    private function testWrongOrderSavesNothing() : void
    {
        $authorTable = AuthorTable::getInstance();
        $bookTable = BookTable::getInstance();
        $author = $authorTable->insert(['name' => 'original']);
        $book = $bookTable->insert(['author_id' => $author->getId(), 'title' => 'original']);
        $newAuthor = AuthorRecord::createNewFromArray(['name' => 'new']);
        $book->setAuthorId($newAuthor->getId());
        $book->setTitle('moved');

        $wrongOrder = new Transaction();
        $wrongOrder->save($book);
        $wrongOrder->save($newAuthor);

        $commitWrongOrder = function () use ($wrongOrder) : void { $wrongOrder->commit(); };
        $this->assertThrows(ExceptionQueryError::class, $commitWrongOrder, "Saving a book before its author worked.");
        $bookInDatabase = $bookTable->load($book->getId(), CacheMode::IGNORE_CACHE);

        if ($bookInDatabase->getTitle() !== 'original' || $this->getAuthorNamesInDatabase() !== ['original'])
        {
            throw new \Exception("A transaction that failed on a foreign key still saved some of its changes.");
        }

        $rightOrder = new Transaction();
        $rightOrder->save($newAuthor);
        $rightOrder->save($book);
        $rightOrder->commit();

        if ($bookTable->load($book->getId(), CacheMode::IGNORE_CACHE)->getAuthorId() !== $newAuthor->getId())
        {
            throw new \Exception("Saving the author before its book didn't work.");
        }
    }


    /**
     * When the last change fails, the earlier saves and deletes are rolled back, and none of the objects are marked as
     * saved or deleted, or cached.
     */
    private function testFailureLeavesEverythingAsItWas() : void
    {
        $authorTable = AuthorTable::getInstance();
        $bookTable = BookTable::getInstance();
        $author = $authorTable->insert(['name' => 'original']);
        $book = $bookTable->insert(['author_id' => $author->getId(), 'title' => 'kept']);
        $author->setName('changed');
        $newAuthor = AuthorRecord::createNewFromArray(['name' => 'new']);

        // name is NOT NULL, so the database rejects this author, which must undo everything before it.
        $invalidAuthor = AuthorRecord::createNewFromArray(['name' => null]);

        $transaction = new Transaction();
        $transaction->delete($book);
        $transaction->save($author);
        $transaction->save($newAuthor);
        $transaction->save($invalidAuthor);

        $commit = function () use ($transaction) : void { $transaction->commit(); };
        $this->assertThrows(ExceptionQueryError::class, $commit, "A transaction with an invalid author didn't fail.");

        $bookCount = count($bookTable->loadAll(CacheMode::IGNORE_CACHE));

        if ($this->getAuthorNamesInDatabase() !== ['original'] || $bookCount !== 1)
        {
            throw new \Exception("A transaction that failed still saved or deleted some rows.");
        }

        if ($book->isDeleted() || !$book->isSavedInDatabase())
        {
            throw new \Exception("A transaction that failed still marked the book as deleted.");
        }

        if ($newAuthor->isSavedInDatabase() || count($authorTable->loadIds([$newAuthor->getId()])) !== 0)
        {
            throw new \Exception("A transaction that failed still marked the new author as saved, or cached it.");
        }
    }


    /**
     * New books whose IDs the database generates, in two separate groups in the same transaction, each get their own
     * row's ID, which needs PostgreSQL 17 or newer. On older servers, the transaction is rejected before anything is
     * sent, including the other changes.
     */
    private function testNewDatabaseIdsInSeveralGroups() : void
    {
        $author = AuthorTable::getInstance()->insert(['name' => 'first']);
        $secondAuthor = AuthorRecord::createNewFromArray(['name' => 'second']);
        $firstBook = BookRecord::createNewFromArray(['author_id' => $author->getId(), 'title' => 'first']);
        $secondBook = BookRecord::createNewFromArray(['author_id' => $secondAuthor->getId(), 'title' => 'second']);
        $thirdBook = BookRecord::createNewFromArray(['author_id' => $secondAuthor->getId(), 'title' => 'third']);

        // three groups: books, then an author, then books, so the two groups of books are separate inserts.
        $transaction = new Transaction();
        $transaction->save($firstBook);
        $transaction->save($secondAuthor);
        $transaction->save($secondBook);
        $transaction->save($thirdBook);

        if (ConnectionHandler::getServerMajorVersion() >= 17)
        {
            $transaction->commit();
            $this->assertBooksHaveTheirOwnIds([$firstBook, $secondBook, $thirdBook]);
        }
        else
        {
            $commit = function () use ($transaction) : void { $transaction->commit(); };
            $failureMessage = "A transaction saving new books didn't throw on PostgreSQL older than 17.";
            $this->assertThrows(ExceptionUnsupportedDatabaseVersion::class, $commit, $failureMessage);

            if ($this->getAuthorNamesInDatabase() !== ['first'] || $secondAuthor->isSavedInDatabase())
            {
                throw new \Exception("A transaction rejected on an older PostgreSQL still saved the new author.");
            }
        }
    }


    /**
     * Inside a transaction that's already open, the changes are made within it, so rolling that back undoes them.
     */
    private function testInsideAnOpenTransaction() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query("BEGIN");
        $transaction = new Transaction();
        $transaction->save(AuthorRecord::createNewFromArray(['name' => 'inside']));
        $transaction->commit();
        $namesBeforeRollback = $this->getAuthorNamesInDatabase();
        $db->query("ROLLBACK");

        if ($namesBeforeRollback !== ['inside'] || $this->getAuthorNamesInDatabase() !== [])
        {
            throw new \Exception("A transaction inside an open one wasn't made within it.");
        }
    }


    /**
     * A transaction that fails inside an open one only undoes its own changes, and leaves the open one usable.
     */
    private function testFailureInsideAnOpenTransaction() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query("BEGIN");
        AuthorTable::getInstance()->insert(['name' => 'before']);
        $transaction = new Transaction();
        $transaction->save(AuthorRecord::createNewFromArray(['name' => 'undone']));
        $transaction->save(AuthorRecord::createNewFromArray(['name' => null]));

        $commit = function () use ($transaction) : void { $transaction->commit(); };
        $this->assertThrows(ExceptionQueryError::class, $commit, "A failing transaction inside an open one worked.");

        // the open transaction must still work after the failure.
        AuthorTable::getInstance()->insert(['name' => 'after']);
        $db->query("COMMIT");
        $names = $this->getAuthorNamesInDatabase();

        if ($names !== ['after', 'before'])
        {
            throw new \Exception("A failing transaction affected the open one around it: " . implode(", ", $names));
        }
    }


    /**
     * Adding an object both to save and delete, committing twice, adding after a commit, saving a deleted object, and
     * mixing connections all throw, while adding the same change twice only makes it once.
     */
    private function testMisuseIsRejected() : void
    {
        $author = AuthorRecord::createNewFromArray(['name' => 'twice']);

        // a new author inserted twice would break its primary key, so this proves it's only saved once.
        $transaction = new Transaction();
        $transaction->save($author);
        $transaction->save($author);
        $transaction->commit();

        $deletedAuthor = AuthorTable::getInstance()->insert(['name' => 'deleted']);
        $deletedAuthor->delete();
        $otherConnectionAuthor = OtherConnectionAuthorRecord::createNewFromArray(['name' => 'other']);
        $unused = new Transaction();
        $unused->save($author);

        $misuses = [
            'saving and deleting the same object' => function () use ($unused, $author) : void {
                $unused->delete($author);
            },
            'mixing connections'                  => function () use ($unused, $otherConnectionAuthor) : void {
                $unused->save($otherConnectionAuthor);
            },
            'committing twice'                    => function () use ($transaction) : void { $transaction->commit(); },
            'adding after the commit'             => function () use ($transaction, $author) : void {
                $transaction->save($author);
            },
        ];

        foreach ($misuses as $misuse => $call)
        {
            $this->assertThrows(ExceptionInvalidTransaction::class, $call, "Transaction allowed {$misuse}.");
        }

        $saveDeleted = function () use ($unused, $deletedAuthor) : void { $unused->save($deletedAuthor); };
        $failureMessage = "Transaction allowed saving a deleted object.";
        $this->assertThrows(ExceptionObjectDeleted::class, $saveDeleted, $failureMessage);

        // a transaction with nothing in it does nothing.
        $empty = new Transaction();
        $empty->commit();
    }


    /**
     * A DEFERRABLE foreign key is only checked once every change has been made, so a review can be saved before its
     * new author, and an author deleted before its reviews, including in a transaction that only deletes.
     */
    private function testDeferrableConstraintsAreDeferred() : void
    {
        $author = AuthorRecord::createNewFromArray(['name' => 'reviewed']);
        $review = ReviewRecord::createNewFromArray(['author_id' => $author->getId(), 'text' => 'great']);

        $saves = new Transaction();
        $saves->save($review); // before its author, which only works as the foreign key is deferred.
        $saves->save($author);
        $saves->commit();

        if (count(ReviewTable::getInstance()->loadAll(CacheMode::IGNORE_CACHE)) !== 1)
        {
            throw new \Exception("A review saved before its author, with a deferrable foreign key, wasn't saved.");
        }

        // only deletes, so there's no save to defer the constraints, which the transaction must do itself.
        $deletes = new Transaction();
        $deletes->delete($author); // before its review, which only works as the foreign key is deferred.
        $deletes->delete($review);
        $deletes->commit();

        $reviewCount = count(ReviewTable::getInstance()->loadAll(CacheMode::IGNORE_CACHE));

        if ($this->getAuthorNamesInDatabase() !== [] || $reviewCount !== 0)
        {
            throw new \Exception("An author deleted before its review, with a deferrable foreign key, wasn't deleted.");
        }
    }


    /**
     * A deferred constraint that's still broken once every change has been made fails the commit, which rolls back
     * every change, and leaves the objects as they were.
     */
    private function testDeferredConstraintFailureSavesNothing() : void
    {
        $author = AuthorRecord::createNewFromArray(['name' => 'saved']);
        $missingAuthor = AuthorRecord::createNewFromArray(['name' => 'never saved']);
        $review = ReviewRecord::createNewFromArray(['author_id' => $missingAuthor->getId(), 'text' => 'orphan']);

        $transaction = new Transaction();
        $transaction->save($author);
        $transaction->save($review);

        $commit = function () use ($transaction) : void { $transaction->commit(); };
        $failureMessage = "A review whose author is never saved didn't fail the commit.";
        $this->assertThrows(ExceptionQueryError::class, $commit, $failureMessage);

        if ($this->getAuthorNamesInDatabase() !== [] || $author->isSavedInDatabase() || $review->isSavedInDatabase())
        {
            throw new \Exception("A commit that failed on a deferred constraint still saved, or marked, some objects.");
        }
    }


    /**
     * Check that each book was given the ID of its own row, and that no two books were given the same ID.
     *
     * @param array<BookRecord> $books
     *
     * @return void
     */
    private function assertBooksHaveTheirOwnIds(array $books) : void
    {
        $ids = [];

        foreach ($books as $book)
        {
            if (!is_int($book->getId()) || !$book->isSavedInDatabase())
            {
                throw new \Exception("The {$book->getTitle()} book wasn't given its generated ID.");
            }

            $bookInDatabase = BookTable::getInstance()->load($book->getId(), CacheMode::IGNORE_CACHE);

            if ($bookInDatabase->getTitle() !== $book->getTitle())
            {
                throw new \Exception("The {$book->getTitle()} book was given the ID of a different row.");
            }

            $ids[$book->getId()] = true;
        }

        if (count($ids) !== count($books))
        {
            throw new \Exception("Two books were given the same ID.");
        }
    }


    /**
     * Check that running $call throws an exception of the given class.
     *
     * @param string $exceptionClass - the class of exception it should throw.
     *
     * @param \Closure $call - the code to run.
     *
     * @param string $failureMessage - the message to fail with if it doesn't throw.
     *
     * @return void
     */
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


    /**
     * Get the names of the authors in the database, in alphabetical order.
     * @return array<string>
     */
    private function getAuthorNamesInDatabase() : array
    {
        $names = [];

        foreach (AuthorTable::getInstance()->loadAll(CacheMode::IGNORE_CACHE) as $author)
        {
            $names[] = $author->getName();
        }

        sort($names);
        return $names;
    }


    private function resetTables() : void
    {
        $db = ConnectionHandler::getDb();
        $tables = implode(", ", $db->escapeIdentifiers(["review", "book", "author"]));
        $db->query("TRUNCATE {$tables} RESTART IDENTITY");
        AuthorTable::getInstance()->emptyCache();
        BookTable::getInstance()->emptyCache();
    }
}
