<?php

/*
 * A pgsql-lib Transaction that can also save and delete ORM records, so that plain queries and records take effect
 * together, or not at all. Queries, callbacks, retries and running it are all handled by Transaction.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;

use PgSql\Result;
use Programster\PgsqlLib\ClosureCollection;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidQuery;
use Programster\PgsqlLib\Exceptions\ExceptionInvalidTransaction;
use Programster\PgsqlLib\Exceptions\ExceptionQueryError;
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlLib\Query;
use Programster\PgsqlLib\QueryCollection;
use Programster\PgsqlLib\QueryResultCollection;
use Programster\PgsqlLib\Transaction;
use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;
use Programster\PgsqlObjects\Exceptions\ExceptionObjectDeleted;

/**
 * Nothing is sent to the database until it's executed. Changes run in the order they were added, and neighbouring
 * saves or deletes of the same table are combined into as few statements as possible. Record values are read when it's
 * executed, and objects and the cache are only updated once it has succeeded.
 *
 * It can only be executed once, whether or not that succeeds, as its objects have been updated by then.
 *
 * @phpstan-type RecordStep array{
 *     numQueriesBefore: int,
 *     table: AbstractTable<covariant AbstractRecord>,
 *     saves: list<AbstractRecord>,
 *     deletes: list<AbstractRecord>,
 * }
 * @phpstan-type TakeOn array{
 *     table: AbstractTable<covariant AbstractRecord>,
 *     objects: list<AbstractRecord>,
 *     position: int,
 * }
 */
final class OrmTransaction extends Transaction
{
    private const DEFER_CONSTRAINTS = 'SET CONSTRAINTS ALL DEFERRED';

    /** @var list<RecordStep> - neighbouring saves or deletes of one table, and where they go among the queries. */
    private array $m_recordSteps = [];

    /** @var array<int, true> - the objects being saved, by their spl_object_id(). */
    private array $m_objectIdsToSave = [];

    /** @var array<int, true> - the objects being deleted, by their spl_object_id(). */
    private array $m_objectIdsToDelete = [];

    /** @var list<TakeOn> - what to update once it has succeeded. A position of -1 means there's no result to read. */
    private array $m_takeOns = [];

    /** @var list<int> - the position of each plain query among everything that's run. */
    private array $m_plainQueryPositions = [];


    /**
     * Create a transaction.
     *
     * @param PgSqlConnection $db - the connection to run it on, which every record's table must also use.
     *
     * @param QueryCollection|array<string|Query> $queries - plain queries to start with. Defaults to none.
     *
     * @param ClosureCollection|array<\Closure> $afterCommitCallbacks - what to do once it has committed and its objects
     * have been updated. They don't run if it fails, and all of them run, even if one throws. Defaults to none.
     *
     * @param int $maxAttempts - how many times to attempt it in total, including the first, if it fails with a
     * serialization failure or a deadlock. Nothing else is retried. Use 1 for no retries.
     *
     * @throws ExceptionInvalidQuery - if a string in $queries can't be used as a query.
     *
     * @throws ExceptionInvalidTransaction - if $queries or $afterCommitCallbacks hold the wrong type of value, or
     * $maxAttempts is below 1.
     */
    public function __construct(
        PgSqlConnection $db,
        QueryCollection|array $queries = [],
        ClosureCollection|array $afterCommitCallbacks = [],
        int $maxAttempts = 3
    )
    {
        parent::__construct($db, $queries, $afterCommitCallbacks, $maxAttempts);
    }


    /**
     * Add objects to save when the transaction runs: each row is updated if the object has been saved before, or
     * inserted if it hasn't. Each is saved with the values it has when the transaction runs. Adding the same object to
     * save again does nothing, so it stays where it was first added.
     *
     * @param AbstractRecord ...$objects - the objects to save.
     *
     * @return void
     *
     * @throws ExceptionObjectDeleted - if an object has been deleted. Clone it to save it as a new row.
     *
     * @throws ExceptionInvalidTransaction - if an object is already being deleted in this transaction, its table
     * doesn't extend AbstractTable or uses a different connection, or the transaction has already been executed.
     */
    public function save(AbstractRecord ...$objects) : void
    {
        $this->assertCanRun();

        foreach ($objects as $object)
        {
            $this->assertCanSave($object);
        }

        foreach ($objects as $object)
        {
            $this->addSave($object);
        }
    }


    /**
     * Add objects to delete when the transaction runs. Afterwards they're marked as deleted, just as their delete()
     * would. An object that was never saved has no row to delete, but is still marked as deleted. Adding the same
     * object to delete again, or one that has already been deleted, does nothing.
     *
     * @param AbstractRecord ...$objects - the objects to delete.
     *
     * @return void
     *
     * @throws ExceptionInvalidTransaction - if an object is already being saved in this transaction, its table doesn't
     * extend AbstractTable or uses a different connection, or the transaction has already been executed.
     */
    public function delete(AbstractRecord ...$objects) : void
    {
        $this->assertCanRun();

        foreach ($objects as $object)
        {
            $this->assertCanDelete($object);
        }

        foreach ($objects as $object)
        {
            $this->addDelete($object);
        }
    }


    /**
     * Make every change, in the order they were added, in one database transaction, then update the objects and the
     * cache, then run the callbacks. If a transaction is already open on the connection, the changes are made inside a
     * savepoint within it, so a failure only undoes these changes.
     *
     * @return QueryResultCollection - the results of the plain queries, in order.
     *
     * @throws ExceptionNoSuchIdException - if a row being updated no longer exists, after rolling back.
     *
     * @throws ExceptionQueryError - if a query failed, after rolling back.
     */
    public function execute() : QueryResultCollection
    {
        try
        {
            $results = parent::execute();
        }
        catch (ExceptionQueryError $exception)
        {
            throw AbstractTable::convertSaveError($exception);
        }

        return $results;
    }


    public function count() : int
    {
        return parent::count() + count($this->m_objectIdsToSave) + count($this->m_objectIdsToDelete);
    }


    /**
     * Put the plain queries and the records' statements in the order they were added. Every object is checked here,
     * before anything is sent.
     *
     * @return QueryCollection
     */
    protected function prepareQueries() : QueryCollection
    {
        $plainQueries = array_values(parent::prepareQueries()->toArray());

        // constraints are deferred first, if any records are being changed, so positions start after that.
        $queries = [new Query(self::DEFER_CONSTRAINTS)];
        $numPlainAdded = 0;
        $hasRecordQueries = false;

        foreach ($this->m_recordSteps as $step)
        {
            while ($numPlainAdded < $step['numQueriesBefore'])
            {
                $this->m_plainQueryPositions[] = count($queries);
                $queries[] = $plainQueries[$numPlainAdded];
                $numPlainAdded++;
            }

            $numBefore = count($queries);
            $this->addDeleteQuery($step, $queries);
            $this->addSaveQueries($step, $queries);
            $hasRecordQueries = ($hasRecordQueries || count($queries) > $numBefore);
        }

        while ($numPlainAdded < count($plainQueries))
        {
            $this->m_plainQueryPositions[] = count($queries);
            $queries[] = $plainQueries[$numPlainAdded];
            $numPlainAdded++;
        }

        if ($hasRecordQueries === false)
        {
            array_shift($queries);
            $this->shiftPositionsBack();
        }

        return new QueryCollection(...$queries);
    }


    /**
     * Update the objects and the cache now that the queries have succeeded.
     * @param QueryResultCollection $results - every query's result, in order.
     * @return QueryResultCollection - the results of the plain queries, in order.
     */
    protected function afterSuccess(QueryResultCollection $results) : QueryResultCollection
    {
        $plainResults = [];

        foreach ($this->m_takeOns as $takeOn)
        {
            if ($takeOn['position'] === -1)
            {
                $this->callTable($takeOn['table'], 'takeOnDeletedObjects', $takeOn['objects']);
            }
            else
            {
                $result = $results->get($takeOn['position']);
                $this->callTable($takeOn['table'], 'takeOnSavedBatch', $takeOn['objects'], $result);
            }
        }

        foreach ($this->m_plainQueryPositions as $position)
        {
            $plainResults[] = $results->get($position);
        }

        return new QueryResultCollection(...$plainResults);
    }


    /**
     * Add the query that deletes a step's objects, if any of them have rows.
     * @param RecordStep $step - the step.
     * @param list<Query> $queries - the queries so far, which this adds to.
     * @return void
     */
    private function addDeleteQuery(array $step, array &$queries) : void
    {
        $objects = $this->getObjectsNotYetDeleted($step['deletes']);
        $ids = $this->getIdsOfRows($objects);

        if (count($ids) > 0)
        {
            $queries[] = new Query($this->callTable($step['table'], 'generateDeleteByIdsQuery', $ids));
        }

        if (count($objects) > 0)
        {
            $this->m_takeOns[] = ['table' => $step['table'], 'objects' => $objects, 'position' => -1];
        }
    }


    /**
     * Add the queries that save a step's objects.
     * @param RecordStep $step - the step.
     * @param list<Query> $queries - the queries so far, which this adds to.
     * @return void
     */
    private function addSaveQueries(array $step, array &$queries) : void
    {
        if (count($step['saves']) > 0)
        {
            /** @var list<array{query: Query, objects: list<AbstractRecord>}> $batches */
            $batches = $this->callTable($step['table'], 'generateSaveBatches', $step['saves']);

            foreach ($batches as $batch)
            {
                $position = count($queries);
                $objects = $batch['objects'];
                $queries[] = $batch['query'];
                $this->m_takeOns[] = ['table' => $step['table'], 'objects' => $objects, 'position' => $position];
            }
        }
    }


    /**
     * With no record statements, nothing defers constraints, so every position moves back by one.
     * @return void
     */
    private function shiftPositionsBack() : void
    {
        foreach ($this->m_plainQueryPositions as $index => $position)
        {
            $this->m_plainQueryPositions[$index] = $position - 1;
        }
    }


    /**
     * Add an object to save, joining the last step if that's saving objects of the same table with no query since.
     * @param AbstractRecord $object - the object, already checked by assertCanSave().
     * @return void
     */
    private function addSave(AbstractRecord $object) : void
    {
        $objectId = spl_object_id($object);

        if (!isset($this->m_objectIdsToSave[$objectId]))
        {
            $this->m_objectIdsToSave[$objectId] = true;
            $table = $this->requireTable($object);
            $lastIndex = count($this->m_recordSteps) - 1;

            $joinsLastStep = (
                   $lastIndex >= 0
                && count($this->m_recordSteps[$lastIndex]['saves']) > 0
                && $this->m_recordSteps[$lastIndex]['table'] === $table
                && $this->m_recordSteps[$lastIndex]['numQueriesBefore'] === parent::count()
            );

            if ($joinsLastStep)
            {
                $this->m_recordSteps[$lastIndex]['saves'][] = $object;
            }
            else
            {
                $numQueries = parent::count();

                $this->m_recordSteps[] = [
                    'numQueriesBefore' => $numQueries,
                    'table' => $table,
                    'saves' => [$object],
                    'deletes' => [],
                ];
            }
        }
    }


    /**
     * Add an object to delete, joining the last step if that's deleting objects of the same table with no query since.
     * @param AbstractRecord $object - the object, already checked by assertCanDelete().
     * @return void
     */
    private function addDelete(AbstractRecord $object) : void
    {
        $objectId = spl_object_id($object);

        if (!isset($this->m_objectIdsToDelete[$objectId]))
        {
            $this->m_objectIdsToDelete[$objectId] = true;
            $table = $this->requireTable($object);
            $lastIndex = count($this->m_recordSteps) - 1;

            $joinsLastStep = (
                   $lastIndex >= 0
                && count($this->m_recordSteps[$lastIndex]['deletes']) > 0
                && $this->m_recordSteps[$lastIndex]['table'] === $table
                && $this->m_recordSteps[$lastIndex]['numQueriesBefore'] === parent::count()
            );

            if ($joinsLastStep)
            {
                $this->m_recordSteps[$lastIndex]['deletes'][] = $object;
            }
            else
            {
                $numQueries = parent::count();

                $this->m_recordSteps[] = [
                    'numQueriesBefore' => $numQueries,
                    'table' => $table,
                    'saves' => [],
                    'deletes' => [$object],
                ];
            }
        }
    }


    /**
     * Check that an object can be saved in this transaction.
     * @param AbstractRecord $object - the object.
     * @return void
     * @throws ExceptionObjectDeleted - if it has been deleted.
     * @throws ExceptionInvalidTransaction - if it's already being deleted, or its table can't be used.
     */
    private function assertCanSave(AbstractRecord $object) : void
    {
        $className = get_class($object);

        if ($object->isDeleted())
        {
            throw new ExceptionObjectDeleted("Can't save this {$className} in a transaction, as it has been deleted.");
        }

        if (isset($this->m_objectIdsToDelete[spl_object_id($object)]))
        {
            $this->throwSavedAndDeleted($className);
        }

        $this->requireTable($object);
    }


    /**
     * Check that an object can be deleted in this transaction.
     * @param AbstractRecord $object - the object.
     * @return void
     * @throws ExceptionInvalidTransaction - if it's already being saved, or its table can't be used.
     */
    private function assertCanDelete(AbstractRecord $object) : void
    {
        if (isset($this->m_objectIdsToSave[spl_object_id($object)]))
        {
            $this->throwSavedAndDeleted(get_class($object));
        }

        $this->requireTable($object);
    }


    /**
     * @param string $className - the class of the object.
     * @return never
     * @throws ExceptionInvalidTransaction - always.
     */
    private function throwSavedAndDeleted(string $className) : never
    {
        throw new ExceptionInvalidTransaction(
            "This {$className} can't be both saved and deleted in the same transaction, as only one of them could be"
            . " what was meant."
        );
    }


    /**
     * Get an object's table, checking that it can be part of this transaction.
     * @param AbstractRecord $object - the object.
     * @return AbstractTable<covariant AbstractRecord>
     * @throws ExceptionInvalidTransaction - if its table doesn't extend AbstractTable, or uses another connection.
     */
    private function requireTable(AbstractRecord $object) : AbstractTable
    {
        $table = $object->getTableHandler();

        if (!($table instanceof AbstractTable))
        {
            $tableClass = get_class($table);

            throw new ExceptionInvalidTransaction(
                "Objects of the {$tableClass} table can't be added to a transaction, as it doesn't extend"
                . " AbstractTable."
            );
        }

        if ($table->getDb()->getResource() !== $this->m_db->getResource())
        {
            throw new ExceptionInvalidTransaction(
                "The {$table->getTableName()} table uses a different database connection from this transaction, and a"
                . " transaction can only cover one connection."
            );
        }

        return $table;
    }


    /**
     * Get the IDs of the objects that have rows, leaving out those with no ID yet, as they were never saved.
     * @param array<AbstractRecord> $objects - the objects.
     * @return list<int|string>
     */
    private function getIdsOfRows(array $objects) : array
    {
        $ids = [];

        foreach ($objects as $object)
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
     * @param array<AbstractRecord> $objects - the objects being deleted.
     * @return list<AbstractRecord>
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
     * Call one of a table's private methods, as PHP has no "friend" classes, and these aren't for the public to call.
     *
     * @param AbstractTable<covariant AbstractRecord> $table - the table.
     *
     * @param string $method - the method's name.
     *
     * @param mixed ...$arguments - its arguments.
     *
     * @return mixed - what it returned.
     */
    private function callTable(AbstractTable $table, string $method, mixed ...$arguments) : mixed
    {
        $call = function () use ($method, $arguments) : mixed { return $this->$method(...$arguments); };
        $bound = \Closure::bind($call, $table, AbstractTable::class);
        return $bound();
    }
}
