<?php

/*
 * Saves and deletes records, of any of your tables, together in one database transaction.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\Exceptions\ExceptionInvalidTransaction;
use Programster\PgsqlObjects\Exceptions\ExceptionObjectDeleted;


/**
 * Saves and deletes many records, of any of your tables, together in one database transaction: either every change
 * is made, or none are. Add the objects with save() and delete(), then call commit().
 *
 * Nothing is sent to the database until commit(), which sends every change as one query: a single round trip to the
 * database, however many objects and tables there are. The changes are made in the order they were added, so add a
 * row before any row with a foreign key to it, and delete those before the row they reference. Changes next to each
 * other, of the same kind and table, are combined, as batchSave() and deleteByIds() would.
 *
 * Constraints created DEFERRABLE (e.g. with Constraint and DeferConfig) are only checked once every change has been
 * made, as the transaction defers them, so for those, the order doesn't matter. PostgreSQL's constraints aren't
 * deferrable unless they were created that way, and NOT NULL and CHECK constraints never are.
 *
 * Objects are only marked as saved or deleted, and the cache only updated, once the transaction has been committed. So
 * if it fails, every object is left as it was, and nothing has been saved or deleted.
 *
 * This only saves and deletes records: it doesn't run any other queries. Every object's table must extend
 * AbstractTable, and every table must use the same database connection, as a transaction can only cover one. A foreign
 * key to a row whose ID the database generates (e.g. SERIAL) must already be set when the object is added, as the new
 * ID isn't known until the transaction runs. IDs generated in code, such as UUIDs, are known straight away.
 */
final class Transaction
{
    # The savepoint used when a transaction is already open on the connection.
    private const SAVEPOINT_NAME = 'pgsql_objects_transaction';

    # The changes to make, in the order they were added, each as ['type' => TransactionChangeType, 'object' => the
    # object].
    private array $m_changes = [];

    # The kind of change (a TransactionChangeType) of each object added, by its spl_object_id(), to spot an object
    # added twice.
    private array $m_changeTypesByObjectId = [];

    # Whether commit() has been called, after which nothing more can be added or committed.
    private bool $m_isFinished = false;


    /**
     * Add an object to save when the transaction is committed: its row is updated if the object has been saved
     * before, or inserted if it hasn't. It's saved with the values it has when commit() is called. Adding the same
     * object to save again does nothing, so it stays where it was first added.
     *
     * @param AbstractRecord $object - the object to save.
     *
     * @return void
     *
     * @throws ExceptionObjectDeleted - if the object has been deleted. Clone it to save it as a new row.
     *
     * @throws ExceptionInvalidTransaction - if the object is already being deleted in this transaction, its table
     * doesn't extend AbstractTable or uses a different database connection from the other objects' tables, or the
     * transaction has already been committed.
     */
    public function save(AbstractRecord $object) : void
    {
        if ($object->isDeleted())
        {
            $className = get_class($object);
            throw new ExceptionObjectDeleted("Can't save this {$className} in a transaction, as it has been deleted.");
        }

        $this->addChange(TransactionChangeType::SAVE, $object);
    }


    /**
     * Add an object to delete when the transaction is committed. After the commit, the object is marked as deleted,
     * just as its delete() would, so its save() and update() throw ExceptionObjectDeleted. An object that was never
     * saved has no row to delete, but is still marked as deleted. Adding the same object to delete again, or one that
     * has already been deleted, does nothing.
     *
     * @param AbstractRecord $object - the object to delete.
     *
     * @return void
     *
     * @throws ExceptionInvalidTransaction - if the object is already being saved in this transaction, its table
     * doesn't extend AbstractTable or uses a different database connection from the other objects' tables, or the
     * transaction has already been committed.
     */
    public function delete(AbstractRecord $object) : void
    {
        $this->addChange(TransactionChangeType::DELETE, $object);
    }


    /**
     * Make every change, in the order they were added, in one database transaction, sent as a single query, and then
     * mark the objects as saved or deleted, and update the cache. If anything fails, the transaction is rolled back,
     * so nothing is saved or deleted, every object is left as it was, and the exception is thrown on. Every object is
     * checked before anything is sent to the database.
     *
     * If a transaction is already open on the connection, the changes are made within it, inside a savepoint, so a
     * failure only undoes these changes. In that case, nothing is final until the open transaction is committed, and
     * if it's rolled back instead, the objects will still be marked as saved or deleted. Its deferrable constraints are
     * deferred too, so they aren't checked until it's committed, and a problem with them only shows then.
     *
     * A transaction can only be committed once, whether or not it succeeds. Committing one with no changes does
     * nothing.
     *
     * @return void
     *
     * @throws ExceptionInvalidTransaction - if the transaction has already been committed.
     *
     * @throws ExceptionObjectDeleted - if an object being saved has been deleted since it was added.
     *
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionUnsupportedDatabaseVersion - if an object being saved has
     * no ID, as the database generates it, and the database is older than PostgreSQL 17 (see batchSave()).
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if a query failed, in which case the transaction
     * was rolled back.
     */
    public function commit() : void
    {
        $this->assertNotFinished();

        // even if this fails, so that it can't be committed again by mistake.
        $this->m_isFinished = true;

        if (count($this->m_changes) > 0)
        {
            $groups = $this->groupChanges();
            $statementsByGroup = $this->generateStatements($groups);
            $results = $this->runStatements($statementsByGroup);
            $this->takeOnChanges($groups, $results);
        }
    }


    /**
     * Add a change to make, after checking that it can be.
     *
     * @param TransactionChangeType $type - whether to save or delete the object.
     *
     * @param AbstractRecord $object - the object to save or delete.
     *
     * @return void
     *
     * @throws ExceptionInvalidTransaction - see save() and delete().
     */
    private function addChange(TransactionChangeType $type, AbstractRecord $object) : void
    {
        $this->assertNotFinished();
        $objectId = spl_object_id($object);

        if (isset($this->m_changeTypesByObjectId[$objectId]))
        {
            if ($this->m_changeTypesByObjectId[$objectId] !== $type)
            {
                $className = get_class($object);

                throw new ExceptionInvalidTransaction(
                    "This {$className} can't be both saved and deleted in the same transaction, as only one of" .
                    " them could be what was meant."
                );
            }
        }
        else
        {
            $this->assertTableCanJoin($object->getTableHandler());
            $this->m_changeTypesByObjectId[$objectId] = $type;
            $this->m_changes[] = ['type' => $type, 'object' => $object];
        }
    }


    /**
     * Group the changes that are next to each other, and are of the same kind and table, so that each group can be
     * sent as one query, while keeping the order they were added in.
     *
     * @return array<array> - the groups, in order, each as ['type' => TransactionChangeType, 'table' => AbstractTable,
     * 'objects' => array<AbstractRecord>].
     */
    private function groupChanges() : array
    {
        $groups = [];

        foreach ($this->m_changes as $change)
        {
            $table = $change['object']->getTableHandler();
            $lastIndex = count($groups) - 1;

            $joinsLastGroup = (
                   $lastIndex >= 0
                && $groups[$lastIndex]['type'] === $change['type']
                && $groups[$lastIndex]['table'] === $table
            );

            if ($joinsLastGroup)
            {
                $groups[$lastIndex]['objects'][] = $change['object'];
            }
            else
            {
                $groups[] = ['type' => $change['type'], 'table' => $table, 'objects' => [$change['object']]];
            }
        }

        return $groups;
    }


    /**
     * Generate the SQL statements for each group, without running any of them, so that every object is checked before
     * anything is sent to the database. A group that only deletes objects with no rows (never saved, or already
     * deleted) needs no statements.
     *
     * @param array<array> $groups - the groups, from groupChanges().
     *
     * @return array<int, array<string>> - each group's statements, keyed by the index of the group, each a single SQL
     * statement.
     *
     * @throws ExceptionObjectDeleted - see commit().
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionUnsupportedDatabaseVersion - see commit().
     */
    private function generateStatements(array $groups) : array
    {
        // the table's statement generators are private to AbstractTable, so call them from within its scope.
        $generateSaveStatements = function (array $objects) : array {
            return $this->generateBatchSaveStatements($objects);
        };

        $generateDeleteQuery = function (array $ids) : string { return $this->generateDeleteByIdsQuery($ids); };
        $statementsByGroup = [];

        foreach ($groups as $index => $group)
        {
            if ($group['type'] === TransactionChangeType::SAVE)
            {
                $bound = \Closure::bind($generateSaveStatements, $group['table'], AbstractTable::class);
                $statementsByGroup[$index] = $bound($group['objects']);
            }
            else
            {
                $ids = $this->getIdsOfRowsToDelete($group['objects']);

                if (count($ids) > 0)
                {
                    $bound = \Closure::bind($generateDeleteQuery, $group['table'], AbstractTable::class);
                    $statementsByGroup[$index] = [$bound($ids)];
                }
            }
        }

        return $statementsByGroup;
    }


    /**
     * Run every group's statements, in order, as one query: a single round trip. PostgreSQL runs the statements of a
     * single query as one transaction, which it rolls back if any of them fail. If a transaction is already open, they
     * run inside a savepoint instead, which is rolled back if they fail, so that only these changes are undone. The
     * query is run by the table's runStatements(), which returns every statement's result, so each saved object can
     * take on its row.
     *
     * @param array<int, array<string>> $statementsByGroup - each group's statements, from generateStatements().
     *
     * @return array<int, array<\PgSql\Result>> - the results of each group's statements, in order, keyed by the
     * group's index.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if a statement failed, after rolling back.
     */
    private function runStatements(array $statementsByGroup) : array
    {
        $resultsByGroup = [];

        if (count($statementsByGroup) > 0)
        {
            $db = $this->getDb();
            $savepoint = $db->escapeIdentifier(self::SAVEPOINT_NAME);
            $isInTransaction = (pg_transaction_status($db->getResource()) !== PGSQL_TRANSACTION_IDLE);
            $statements = [];
            $groupIndexesByStatement = [];

            if ($isInTransaction)
            {
                $statements[] = "SAVEPOINT {$savepoint}";
            }

            // check the constraints that were created DEFERRABLE (such as a foreign key) at the end, rather than after
            // each statement, so the rows only have to be right once every change has been made. This comes before
            // every change, including deletes, which the save groups' own SET CONSTRAINTS wouldn't cover.
            $statements[] = "SET CONSTRAINTS ALL DEFERRED";

            foreach ($statementsByGroup as $groupIndex => $groupStatements)
            {
                foreach ($groupStatements as $statement)
                {
                    $groupIndexesByStatement[count($statements)] = $groupIndex;
                    $statements[] = $statement;
                }
            }

            if ($isInTransaction)
            {
                $statements[] = "RELEASE SAVEPOINT {$savepoint}";
            }

            // runStatements() is private to AbstractTable, so call it from within its scope.
            $runStatements = function (array $statements) : array { return $this->runStatements($statements); };
            $boundRunStatements = \Closure::bind($runStatements, $this->getFirstTable(), AbstractTable::class);

            try
            {
                $results = $boundRunStatements($statements);
            }
            catch (ExceptionQueryError $exception)
            {
                // outside a transaction, PostgreSQL has already rolled the query back, but an open transaction is
                // left failed until the savepoint is rolled back.
                if ($isInTransaction)
                {
                    $this->undo($db, "ROLLBACK TO SAVEPOINT {$savepoint}; RELEASE SAVEPOINT {$savepoint}");
                }

                throw $exception;
            }

            foreach ($groupIndexesByStatement as $statementIndex => $groupIndex)
            {
                $resultsByGroup[$groupIndex][] = $results[$statementIndex];
            }
        }

        return $resultsByGroup;
    }


    /**
     * Roll back to the savepoint after a statement failed. If even that fails (e.g. the open transaction had already
     * failed before the savepoint could be made), it's ignored, so that the exception from the statement that failed is
     * the one that's thrown.
     *
     * @param PgSqlConnection $db - the connection.
     *
     * @param string $undo - the query that rolls back.
     *
     * @return void
     */
    private function undo(PgSqlConnection $db, string $undo) : void
    {
        try
        {
            $db->query($undo);
        }
        catch (\Throwable)
        {
            // the original exception is more useful than this one, and is thrown by the caller.
        }
    }


    /**
     * Mark the objects as saved or deleted, and update the cache, now that the transaction has been committed.
     *
     * @param array<array> $groups - the groups, from groupChanges().
     *
     * @param array<int, array<\PgSql\Result>> $results - the results of each group's statements, keyed by its group's
     * index, from runStatements().
     *
     * @return void
     */
    private function takeOnChanges(array $groups, array $results) : void
    {
        // the table's methods for this are private to AbstractTable, so call them from within its scope.
        $takeOnSaved = function (array $objects, array $results) : void {
            $this->takeOnBatchSavedObjects($objects, $results);
        };

        $takeOnDeleted = function (array $objects) : void { $this->takeOnDeletedObjects($objects); };

        foreach ($groups as $index => $group)
        {
            if ($group['type'] === TransactionChangeType::SAVE)
            {
                $bound = \Closure::bind($takeOnSaved, $group['table'], AbstractTable::class);
                $bound($group['objects'], $results[$index]);
            }
            else
            {
                $objectsToMark = $this->getObjectsNotYetDeleted($group['objects']);
                $bound = \Closure::bind($takeOnDeleted, $group['table'], AbstractTable::class);
                $bound($objectsToMark);
            }
        }
    }


    /**
     * Get the IDs of the rows to delete for some objects, leaving out objects that have no row: those with no ID yet
     * (as the database generates it, and they haven't been saved), and those that have already been deleted.
     *
     * @param array<AbstractRecord> $objects - the objects being deleted.
     *
     * @return array<int|string>
     */
    private function getIdsOfRowsToDelete(array $objects) : array
    {
        $ids = [];

        foreach ($this->getObjectsNotYetDeleted($objects) as $object)
        {
            if ($object->getId() !== null)
            {
                $ids[] = $object->getId();
            }
        }

        return $ids;
    }


    /**
     * Leave out the objects that have already been deleted, which deleting again does nothing to.
     *
     * @param array<AbstractRecord> $objects
     *
     * @return array<AbstractRecord>
     */
    private function getObjectsNotYetDeleted(array $objects) : array
    {
        $objectsNotYetDeleted = [];

        foreach ($objects as $object)
        {
            if ($object->isDeleted() === false)
            {
                $objectsNotYetDeleted[] = $object;
            }
        }

        return $objectsNotYetDeleted;
    }


    /**
     * Check that an object of this table can be added: its table must extend AbstractTable, and use the same database
     * connection as the tables of the objects already added.
     *
     * @param TableInterface $table - the object's table.
     *
     * @return void
     *
     * @throws ExceptionInvalidTransaction - if it can't be.
     */
    private function assertTableCanJoin(TableInterface $table) : void
    {
        if (!($table instanceof AbstractTable))
        {
            $className = get_class($table);

            throw new ExceptionInvalidTransaction(
                "Objects of the {$className} table can't be added to a transaction, as it doesn't extend AbstractTable."
            );
        }

        if (count($this->m_changes) > 0 && $table->getDb() !== $this->getDb())
        {
            throw new ExceptionInvalidTransaction(
                "The {$table->getTableName()} table uses a different database connection from the other objects in" .
                " this transaction, and a transaction can only cover one connection."
            );
        }
    }


    /**
     * Check that the transaction hasn't already been committed.
     *
     * @return void
     *
     * @throws ExceptionInvalidTransaction - if it has.
     */
    private function assertNotFinished() : void
    {
        if ($this->m_isFinished)
        {
            throw new ExceptionInvalidTransaction(
                "This transaction has already been committed, so nothing more can be added to it, and it can't be" .
                " committed again. Create a new Transaction instead."
            );
        }
    }


    /**
     * Get the database connection, from the table of the first object added, which every table shares.
     * @return PgSqlConnection
     */
    private function getDb() : PgSqlConnection
    {
        return $this->getFirstTable()->getDb();
    }


    /**
     * Get the table of the first object added, which extends AbstractTable, as every table added must.
     * @return AbstractTable
     */
    private function getFirstTable() : AbstractTable
    {
        return $this->m_changes[0]['object']->getTableHandler();
    }
}
