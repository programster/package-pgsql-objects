<?php

/*
 * A class to represent a table in the database. Use this for the loading/deleting of objects/rows.
 */


declare(strict_types = 1);

namespace Programster\PgsqlObjects;

use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlLib\Table;
use Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData;
use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;
use Programster\PgsqlObjects\Exceptions\ExceptionTableNotFound;


/**
 * @template T of \Programster\PgsqlObjects\AbstractTableRowObject
 */
abstract class AbstractTable implements TableInterface
{
    # Array of all the child instances that get created.
    protected static array $s_instances = [];


    # Cache of loaded objects so we don't need to go and re-fetch them.
    # This object needs to ensure we clear these when we update rows.
    protected array $m_objectCache = [];


    # protected constructor so that one has to use the getInstance method.
    protected function __construct()
    {

    }


    /**
     * Fetch the single instance of this object.
     * @return static
     */
    public static function getInstance() : static
    {
        $className = get_called_class();

        if (!isset(self::$s_instances[$className]))
        {
            self::$s_instances[$className] = new static();
        }

        return self::$s_instances[$className];
    }


    /**
     * Insert a new row into the table, and return the object for it. If no ID is given, one is generated in code if
     * the table can do so, otherwise the database generates it. The object is built from the row the database
     * returns, so it includes any values the database generated, such as a SERIAL ID, column defaults, or generated
     * columns.
     *
     * @param array $row - name value pairs to insert. Any values for the fields listed in getVirtualFields() are
     * ignored, as the database generates them.
     *
     * @return AbstractTableRowObject - the object for the inserted row.
     *
     * @throws ExceptionMissingRequiredData - if no ID was provided, and neither the code nor the database can
     * generate one.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function insert(array $row) : AbstractTableRowObject
    {
        $row = $this->prepareRowForInsert($row);

        if (count($row) > 0)
        {
            $escapedColumnNames = $this->getDb()->escapeIdentifiers(array_keys($row));
            $escapedValues = $this->getDb()->escapeValues(array_values($row)); // null values come back as NULL

            $query =
                "INSERT INTO {$this->getEscapedQualifiedTableName()}" .
                " (" . implode(",", $escapedColumnNames) . ")" .
                " VALUES (" . implode(", ", $escapedValues) . ")";
        }
        else
        {
            $query = "INSERT INTO {$this->getEscapedQualifiedTableName()} DEFAULT VALUES";
        }

        $result = $this->getDb()->query($query . " RETURNING *");
        $createdRow = pg_fetch_assoc($result);
        $fieldInfoMap = $this->getFieldInfoMap();
        $rowHydrator = $this->getRowHydrator();
        $object = $rowHydrator($createdRow, $fieldInfoMap);
        $this->updateCache($object);
        return $object;
    }


    /**
     * Insert many rows into the table in a single query, and return the objects for them. This works whether the IDs
     * are generated in code or by the database (e.g. SERIAL), as the objects are built from the rows the database
     * returns, so they include the generated IDs, column defaults and generated columns. Each row is handled as by
     * insert(): a row without an ID gets one generated in code if the table can do so, otherwise from the database.
     * The rows don't all need the same columns: a column that a row leaves out gets its database default, as long as
     * it is the ID column (when the database generates IDs), or is listed in getFieldsThatHaveDefaults() or
     * getFieldsThatAllowNull().
     * WARNING - the returned objects are not necessarily in the same order as $rows, as PostgreSQL doesn't guarantee
     * the order it returns inserted rows in. Use their values (e.g. a unique column) to tell them apart if needed.
     *
     * @param array<array> $rows - the rows to insert, as name value pairs. Any values for the fields listed in
     * getVirtualFields() are ignored, as the database generates them. Must not be empty.
     *
     * @return array<AbstractTableRowObject> - the objects for the inserted rows.
     *
     * @throws ExceptionMissingRequiredData - if $rows is empty, if a row has no ID and neither the code nor the
     * database can generate one, or if no row has any columns at all. This is checked before anything is inserted.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionMismatchedRows - if a row leaves out a column that other rows
     * provide, and that column has no database default.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if the insert failed, in which case none of the
     * rows are inserted.
     */
    public function batchInsert(array $rows) : array
    {
        if (count($rows) === 0)
        {
            $tableName = $this->getTableName();
            throw new ExceptionMissingRequiredData("batchInsert() was given no rows to insert into {$tableName}.");
        }

        $preparedRows = [];
        $hasColumns = false;

        foreach ($rows as $key => $row)
        {
            $preparedRow = $this->prepareRowForInsert($row);
            $preparedRows[$key] = $preparedRow;
            $hasColumns = ($hasColumns || count($preparedRow) > 0);
        }

        if ($hasColumns === false)
        {
            throw new ExceptionMissingRequiredData(
                "batchInsert() was given rows that have no columns for the {$this->getTableName()} table, so there" .
                " is nothing to insert. Use insert() for each row instead, to insert rows of only default values."
            );
        }

        $columnsWithDefaults = [...$this->getFieldsThatHaveDefaults(), ...$this->getFieldsThatAllowNull()];

        if ($this->canDatabaseGenerateId())
        {
            $columnsWithDefaults[] = $this->getIdColumnName();
        }

        $query = $this->getDb()->generateBatchInsertQuery($this->getTable(), $preparedRows, $columnsWithDefaults);
        $result = $this->getDb()->query($query . " RETURNING *");
        return $this->convertPgResultToObjects($result);
    }


    /**
     * Load objects from the table that have ALL of the attributes specified in the provided wherePairs parameter.
     *
     * @param array $wherePairs - column-name/value pairs that the object must have in order to be fetched. The value
     * in a pair may be an array, to load any objects that have any one of those values, e.g. id => [1,2,3] would load
     * objects that have ID 1, 2 or 3. Must not be empty. Use loadAll() to load every row.
     *
     * @return array<AbstractTableRowObject>
     *
     * @throws ExceptionMissingRequiredData - if $wherePairs is empty.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadWhereAnd(array $wherePairs) : array
    {
        if (count($wherePairs) === 0)
        {
            throw new ExceptionMissingRequiredData(
                "loadWhereAnd() was given no where pairs, which would load every row in the " .
                "{$this->getTableName()} table. If that is intended, use loadAll()."
            );
        }

        $query = $this->getDb()->generateSelectWhereQuery(
            $this->getTable(),
            $wherePairs,
            Conjunction::AND
        );

        $result = $this->getDb()->query($query);
        return $this->convertPgResultToObjects($result);
    }


    /**
     * Load objects from the table that meet ANY of the attributes specified in the provided wherePairs parameter.
     *
     * @param array $wherePairs - column-name/value pairs that the object must have at least one of in order to be
     * fetched. The value in a pair may be an array, to load any objects that have any one of those values, e.g.
     * id => [1,2,3] would load objects that have ID 1, 2 or 3. Must not be empty. Use loadAll() to load every row.
     *
     * @return array<AbstractTableRowObject>
     *
     * @throws ExceptionMissingRequiredData - if $wherePairs is empty.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadWhereOr(array $wherePairs) : array
    {
        if (count($wherePairs) === 0)
        {
            throw new ExceptionMissingRequiredData(
                "loadWhereOr() was given no where pairs, which would load every row in the " .
                "{$this->getTableName()} table. If that is intended, use loadAll()."
            );
        }

        $query = $this->getDb()->generateSelectWhereQuery(
            $this->getTable(),
            $wherePairs,
            Conjunction::OR
        );

        $result = $this->getDb()->query($query); // throws on failure
        return $this->convertPgResultToObjects($result);
    }


    /**
     * Completely empty the cache. Do this if a table is emptied etc.
     * @return void
     */
    public function emptyCache() : void
    {
        $this->m_objectCache = [];
    }


    /**
     * Delete the row with the given ID, and remove its object from the cache. This doesn't throw if there is no row
     * with the ID. To delete a row object you already have, you can call delete() on the object itself instead.
     *
     * @param int|string $id - the identifier of the row you wish to delete.
     *
     * @return void
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function deleteById(int|string $id) : void
    {
        $escapedValue = $this->getDb()->escapeValue($id);
        $tableName = $this->getEscapedQualifiedTableName();
        $query = "DELETE FROM {$tableName} WHERE {$this->getEscapedIdColumnName()} = {$escapedValue}";
        $this->getDb()->query($query);
        $this->unsetCache($id);
    }


    /**
     * Delete the rows with the given IDs in a single query, and remove their objects from the cache. This doesn't
     * throw if there are no rows with some of the IDs. An empty list deletes nothing, and runs no query.
     *
     * @param array<int|string> $ids - the identifiers of the rows you wish to delete.
     *
     * @return void
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function deleteByIds(array $ids) : void
    {
        // an empty IN() is invalid SQL, and there is nothing to delete anyway.
        if (count($ids) > 0)
        {
            $escapedIds = $this->getDb()->escapeValues($ids);

            $query =
                "DELETE FROM {$this->getEscapedQualifiedTableName()}" .
                " WHERE {$this->getEscapedIdColumnName()} IN(" . implode(", ", $escapedIds) . ")";

            $this->getDb()->query($query);

            foreach ($ids as $id)
            {
                $this->unsetCache($id);
            }
        }
    }


    /**
     * Get the escaped, schema-qualified name of this table, ready for use in a query, e.g. "public"."user".
     * @return string
     */
    public function getEscapedQualifiedTableName() : string
    {
        return $this->getDb()->escapeTableName($this->getTable());
    }


    /**
     * Get this table as a pgsql-lib Table, in its schema, for passing to the pgsql-lib query generators.
     * @return Table
     */
    public function getTable() : Table
    {
        return new Table($this->getTableName(), $this->getSchemaName());
    }


    /**
     * Deletes all rows from the table, and empties the cache. Either way, it can be rolled back if run inside a
     * transaction.
     *
     * @param bool $useTruncate - (optional) set to true to run TRUNCATE instead of DELETE, which is much faster on a
     * large table, but: fails if any other table has a foreign key referencing this one (even if no rows reference
     * it), doesn't fire ON DELETE triggers, and takes an ACCESS EXCLUSIVE lock, blocking all other reads and writes
     * on the table until the transaction ends. Defaults to false, which runs DELETE.
     *
     * @return void
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function deleteAll(bool $useTruncate = false) : void
    {
        if ($useTruncate)
        {
            $query = "TRUNCATE {$this->getEscapedQualifiedTableName()}";
        }
        else
        {
            $query = "DELETE FROM {$this->getEscapedQualifiedTableName()}";
        }

        $this->getDb()->query($query);
        $this->emptyCache();
    }


    /**
     * Get the class name of the object we are going to construct.
     * @return class-string<T>
     */
    public abstract function getObjectClassName() : string;


    /**
     * Return the database connection to the database that has this table.
     * @return PgSqlConnection
     */
    public abstract function getDb() : PgSqlConnection;


    /**
     * Get the user to specify fields that may be null in the database and thus don't have
     * to be set when creating this object.
     *
     * @return array<string> - array of column names that may be null.
     */
    abstract public function getFieldsThatAllowNull() : array;


    /**
     * Get the user to specify fields that have default values and thus don't have
     * to be set when creating this object.
     *
     * @return array<string> - array of column names that have default values.
     */
    abstract public function getFieldsThatHaveDefaults() : array;


    /**
     * Get the user to specify the virtual fields. These are columns whose values PostgreSQL always computes itself,
     * i.e. PostgreSQL generated columns (GENERATED ALWAYS AS (...), both STORED and VIRTUAL), or non-ID
     * GENERATED ALWAYS AS IDENTITY columns. This is unrelated to generating IDs (see generateId()), as this
     * package never generates these values itself. They don't have to be set when creating this object, and are
     * never sent to the database in inserts or updates, as PostgreSQL rejects any value for them. Their values are
     * read back from the database after save(), update(), insert() and updateById().
     * Return an empty array if the table has none.
     *
     * @return array<string> - array of column names.
     */
    abstract public function getVirtualFields() : array;


    /**
     * Get the user to specify fields that should be kept as the string the database returns, rather than being
     * converted to a PHP int/float/bool. Use this for numeric columns where converting to a float would lose
     * precision, e.g. currency amounts or high-precision values that you'll handle with bcmath or a decimal library.
     * Override this if needed. By default, no fields are kept as strings, so every column gets converted.
     *
     * @return array<string> - array of column names.
     */
    public function getFieldsToKeepAsString() : array
    {
        return [];
    }


    /**
     * Return the name of this table as it appears in the database.
     */
    public abstract function getTableName() : string;


    /**
     * Return the name of the schema this table is in. Override this if your table is not in the public schema.
     * The table is always referred to by its schema-qualified name, so the connection's search_path is not used.
     *
     * @return string
     */
    public function getSchemaName() : string
    {
        return "public";
    }


    /**
     * Loads a single object of this table's row type from the database using the unique identifier.
     *
     * @param string|int $id - the ID of the row in the database table.
     *
     * @param bool $useCache - optionally set to false to force a database lookup even if we have a
     * cached value from a previous lookup.
     *
     * @return T - the loaded object
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException
     */
    public function load(string|int $id, bool $useCache=true) : AbstractTableRowObject
    {
        $objects = $this->loadIds([$id], $useCache);

        if (count($objects) === 0)
        {
            $msg = "There is no {$this->getObjectClassName()} with {$this->getIdColumnName()}: {$id}";
            throw new ExceptionNoSuchIdException($msg);
        }

        return $objects[array_key_first($objects)];
    }


    /**
     * Loads a number of objects of this table's row type from the database using the provided array
     * list of IDs. If any of the objects are already in the cache, they are fetched from there unless
     * useCache is overridden to false.
     *
     * NOTE: The returned array of objects is indexed by the IDs of the objects.
     *
     * @param array<string|int> $ids - the list of IDs of the objects we wish to load.
     *
     * @param bool $useCache - optionally set to false to force a database lookup even if we have a
     * cached value from a previous lookup.
     *
     * @return array<string|int, T> - list of the objects with the specified IDs indexed by the objects ID.
     *
     * @psalm-return array<string|int, T>
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadIds(array $ids, bool $useCache=true) : array
    {
        $loadedObjects = [];
        $rowHydrator = $this->getRowHydrator();
        $idsToFetch = [];

        foreach ($ids as $id)
        {
            if (!isset($this->m_objectCache[$id]) || !$useCache)
            {
                $idsToFetch[] = $id;
            }
            else
            {
                $loadedObjects[$id] = $this->m_objectCache[$id];
            }
        }

        if (count($idsToFetch) > 0)
        {
            $escapedIds = $this->getDb()->escapeValues($idsToFetch);

            $query =
                "SELECT * FROM {$this->getEscapedQualifiedTableName()}" .
                " WHERE {$this->getEscapedIdColumnName()} IN(" . implode(", ", $escapedIds) . ")";

            /* @var $result \Pgsql\Result */
            $result = $this->getDb()->query($query);
            $fieldInfoMap = $this->getFieldInfoMap();

            while (($row = pg_fetch_assoc($result)) !== false)
            {
                /* @var $object AbstractTableRowObject */
                $object = $rowHydrator($row, $fieldInfoMap);
                $objectId = $object->getId();
                $this->m_objectCache[$objectId] = $object;
                $loadedObjects[$objectId] = $this->m_objectCache[$objectId];
            }
        }

        return $loadedObjects;
    }


    /**
     * Loads all of these objects from the database. This also clears and fully loads the cache.
     * @return array<AbstractTableRowObject>
     */
    public function loadAll() : array
    {
        $this->emptyCache();
        $query = "SELECT * FROM {$this->getEscapedQualifiedTableName()}";
        $result = $this->getDb()->query($query);
        return $this->convertPgResultToObjects($result);
    }


    /**
     * Loads a range of rows from the table, in a stable order so that successive calls can page through the table.
     * Rows are ordered by the ID column by default, or by $orderByColumn if provided, with the ID column used as a
     * tiebreaker so that rows with equal values keep a consistent order.
     * Note that rows inserted or deleted before the current page will shift later pages. If you need to page reliably
     * through a table that is being written to, or deep into a large table, use loadAfterId() instead.
     *
     * @param int $offset - the number of rows to skip.
     *
     * @param int $numElements - the maximum number of rows to load.
     *
     * @param string|null $orderByColumn - optionally the name of the column to order by. Defaults to the ID column.
     *
     * @return array<AbstractTableRowObject>
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadRange(int $offset, int $numElements, ?string $orderByColumn = null) : array
    {
        $orderByColumns = [$this->getEscapedIdColumnName()];

        if ($orderByColumn !== null && $orderByColumn !== $this->getIdColumnName())
        {
            array_unshift($orderByColumns, $this->getDb()->escapeIdentifier($orderByColumn));
        }

        $query =
            "SELECT * FROM {$this->getEscapedQualifiedTableName()}" .
            " ORDER BY " . implode(", ", $orderByColumns) .
            " OFFSET {$offset} LIMIT {$numElements}";

        $result = $this->getDb()->query($query); // throws on failure
        return $this->convertPgResultToObjects($result);
    }


    /**
     * Loads up to $numElements rows whose IDs come after $afterId, in ID order. This is "keyset" pagination: pass null
     * to load the first page, then the ID of the last object on each page to load the next, until an empty page is
     * returned. Unlike loadRange(), this never skips or repeats rows when rows are inserted, updated, or deleted
     * between pages, and stays fast however deep into the table you go.
     *
     * @param string|int|null $afterId - the ID of the last object on the previous page, or null for the first page.
     *
     * @param int $numElements - the maximum number of rows to load.
     *
     * @return array<AbstractTableRowObject>
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadAfterId(string|int|null $afterId, int $numElements) : array
    {
        $whereClause = "";

        if ($afterId !== null)
        {
            $whereClause = " WHERE {$this->getEscapedIdColumnName()} > {$this->getDb()->escapeValue($afterId)}";
        }

        $query =
            "SELECT * FROM {$this->getEscapedQualifiedTableName()}" .
            $whereClause .
            " ORDER BY {$this->getEscapedIdColumnName()}" .
            " LIMIT {$numElements}";

        $result = $this->getDb()->query($query); // throws on failure
        return $this->convertPgResultToObjects($result);
    }


    /**
     * Remove the cache entry for an object.
     * This should only happen when objects are destroyed.
     * This will not throw exception/error if id doesn't exist.
     *
     * @param int|string $objectId - the ID of the object we wish to clear the cache of.
     *
     * @return void
     */
    public function unsetCache(int|string $objectId) : void
    {
        unset($this->m_objectCache[$objectId]);
    }


    /**
     * Update a row specified by the ID with the provided data.
     * The returned object is built from the row the database returns, so it includes any values the database
     * changed, such as generated columns.
     *
     * @param int|string $id - the current ID of the object being updated (may not necessarily be the same as the
     * ID column in $row if changing the object's ID).
     *
     * @param array $row - the data to update the object with. Any values for the fields listed in
     * getVirtualFields() are ignored, as the database generates them.
     *
     * @return AbstractTableRowObject
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if the query failed.
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException - if there is no row with the ID.
     */
    public function updateById(int|string $id, array $row) : AbstractTableRowObject
    {
        # This logic must not ever be changed to load the row object and then call update on that
        # because it's update method will call this method and you will end up with a loop.
        $row = $this->removeVirtualFields($row);
        $idColumnName = $this->getIdColumnName();

        if (count($row) > 0)
        {
            $query =
                "UPDATE {$this->getEscapedQualifiedTableName()} SET " .
                $this->getDb()->generateQueryPairs($row) .
                " WHERE {$this->getDb()->generateQueryPairs([$idColumnName => $id])}" .
                " RETURNING *";

            $result = $this->getDb()->query($query); // throws on failure
            $updatedRow = pg_fetch_assoc($result);

            if ($updatedRow === false)
            {
                $msg = "There is no {$this->getObjectClassName()} with {$idColumnName}: {$id} to update.";
                throw new ExceptionNoSuchIdException($msg);
            }

            $fieldInfoMap = $this->getFieldInfoMap();
            $rowHydrator = $this->getRowHydrator();
            $updatedObject = $rowHydrator($updatedRow, $fieldInfoMap);
            $this->updateCache($updatedObject);
        }
        else
        {
            # nothing that can be written was provided, so just return the row as it is.
            $updatedObject = $this->load($id, false);
        }

        # If we changed the object's ID, then we need to remove the old cached object. The IDs are compared as
        # strings, as the database returns integer IDs as strings.
        if (isset($row[$idColumnName]) && (string)$row[$idColumnName] !== (string)$id)
        {
            $this->unsetCache($id);
        }

        return $updatedObject;
    }


    /**
     * Delete rows from this table whose IDs are not in the provided list.
     *
     * @param array $ids - a list of the only IDs we wish to keep. Must not be empty, as that would delete every row.
     * Use deleteAll() to delete every row.
     *
     * @return void
     *
     * @throws ExceptionMissingRequiredData - if $ids is empty, rather than deleting every row.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function deleteIdsNotIn(array $ids) : void
    {
        if (count($ids) === 0)
        {
            throw new ExceptionMissingRequiredData(
                "deleteIdsNotIn() was given no IDs to keep, which would delete every row in the " .
                "{$this->getTableName()} table. If that is intended, use deleteAll()."
            );
        }

        $escapedIds = $this->getDb()->escapeValues($ids);

        $query =
            "DELETE FROM {$this->getEscapedQualifiedTableName()}" .
            " WHERE {$this->getEscapedIdColumnName()} NOT IN (" . implode(", ", $escapedIds) . ")";

        $this->getDb()->query($query); // throws exception on error

        // Remove the deleted objects from the cache. The IDs are compared as strings, as integer IDs may be cached
        // under an int key but passed in as strings, or the other way around.
        $idsToKeep = [];

        foreach ($ids as $id)
        {
            $idsToKeep[(string)$id] = true;
        }

        foreach (array_keys($this->m_objectCache) as $cachedId)
        {
            if (!isset($idsToKeep[(string)$cachedId]))
            {
                $this->unsetCache($cachedId);
            }
        }
    }


    /**
     * Batch update/insert a bunch of objects in a single query.
     * Every object must have an ID, as there is no way to read IDs generated by the database back from a batch.
     * New objects get one in code when they are created, if the table can generate one, so the only objects without
     * one are those whose IDs are generated by the database. To insert many of those at once, use batchInsert().
     * WARNING - the values of the fields listed in getVirtualFields() can't be read back from a batch either,
     * so they will be out of date on the objects (and in the cache) until they are reloaded with useCache=false.
     *
     * @param array<AbstractTableRowObject> $objects
     *
     * @return void
     *
     * @throws ExceptionMissingRequiredData - if an object has no ID. This is checked before anything is saved, so none
     * of the objects will have been saved.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function batchSave(array $objects) : void
    {
        foreach ($objects as $object)
        {
            if ($object->getId() === null)
            {
                throw new ExceptionMissingRequiredData(
                    "Can't batch save {$this->getTableName()} objects that don't have IDs, as the database generates" .
                    " their IDs. Insert their rows with batchInsert() instead, which returns objects with their IDs."
                );
            }
        }

        if (count($objects) > 0)
        {
            $queries = [
                // dont check for uniqueness until the end of the transaction to resolve issues with reorgnizing sort
                // indexes https://www.postgresql.org/docs/9.1/sql-set-constraints.html
                "SET CONSTRAINTS ALL DEFERRED"
            ];

            foreach ($objects as $object)
            {
                $queries[] = $object->getSaveQuery();
            }

            $multiQuery = implode(";", $queries) . ";";

            // the statements run as a single implicit transaction, so if any fail, query() throws and none are saved.
            $this->getDb()->query($multiQuery);

            foreach ($objects as $object)
            {
                $object->markSaved(); // just in case it was an insert instead of update call.
                $this->updateCache($object);
            }
        }
    }


    /**
     * Load objects whose IDs were not provided.
     * @param array $ids
     * @return AbstractTableRowObject[]
     */
    public function loadIdsNotIn(array $ids) : array
    {
        $tableName = $this->getEscapedQualifiedTableName();

        if (count($ids) > 0)
        {
            $idsString = implode(", ", $this->getDb()->escapeValues($ids));
            $query = "SELECT * FROM {$tableName} WHERE {$this->getEscapedIdColumnName()} NOT IN ({$idsString})";
        }
        else
        {
            $query = "SELECT * FROM {$tableName}";
        }

        $result = $this->getDb()->query($query);
        return $this->convertPgResultToObjects($result);
    }


    /**
     * Specifies the name of the identifier column. This will commonly be "id" or "uuid".
     * @return string
     */
    public function getIdColumnName() : string
    {
        return "id";
    }


    /**
     * Get the escaped form of the name of the column that acts as the identifier.
     * This is deliberately not cached in a static variable, as static variables in methods are
     * shared between all subclasses that inherit the method, so tables with different ID column
     * names would end up using whichever table called this first.
     *
     * @return string
     */
    public function getEscapedIdColumnName() : string
    {
        return $this->getDb()->escapeIdentifier($this->getIdColumnName());
    }


    /**
     * Helper function that converts a query result into a collection of the row objects.
     * @param \Pgsql\Result $result
     * @return T[]
     */
    protected function convertPgResultToObjects(\Pgsql\Result $result) : array
    {
        $objects = [];

        if (pg_num_rows($result) > 0)
        {
            $fieldInfoMap = $this->getFieldInfoMap();
            $rowHydrator = $this->getRowHydrator();

            while (($row = pg_fetch_assoc($result)) !== false)
            {
                $loadedObject = $rowHydrator($row, $fieldInfoMap);
                $this->updateCache($loadedObject);
                $objects[] = $loadedObject;
            }
        }

        return $objects;
    }


    /**
     * Delete rows from the table that have ALL of the attributes specified in the provided wherePairs parameter, and
     * remove their objects from the cache. This loads the matching rows first, so that it knows which objects to
     * remove from the cache, then deletes them by ID.
     *
     * @param array $wherePairs - column-name/value pairs that the rows must all have in order to be deleted.
     * Must not be empty. Use deleteAll() to delete every row.
     *
     * @return void
     *
     * @throws ExceptionMissingRequiredData - if $wherePairs is empty, rather than deleting every row.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    protected function deleteWhereAnd(array $wherePairs) : void
    {
        if (count($wherePairs) === 0)
        {
            throw new ExceptionMissingRequiredData(
                "deleteWhereAnd() was given no where pairs, which would delete every row in the " .
                "{$this->getTableName()} table. If that is intended, use deleteAll()."
            );
        }

        $objects = $this->loadWhereAnd($wherePairs);

        if (count($objects) > 0)
        {
            $objectIds = [];

            foreach ($objects as $object)
            {
                /* @var $object AbstractTableRowObject */
                $objectIds[] = $object->getId();
            }

            $this->deleteByIds($objectIds);
        }
    }


    /**
     * Update our cache with the provided object.
     * Note that if you simply changed the object's ID, you will need to call unsetCache() on
     * the original ID.
     * Objects without an ID yet (e.g. a database-generated ID that hasn't been read back) are not
     * cached, as they have nothing to be looked up by.
     *
     * @param AbstractTableRowObject $object
     *
     * @return void
     */
    protected function updateCache(AbstractTableRowObject $object) : void
    {
        if ($object->getId() !== null)
        {
            $this->m_objectCache[$object->getId()] = $object;
        }
    }


    /**
     * Delete rows from the table that meet ANY of the attributes specified in the provided wherePairs parameter.
     * This empties the whole cache afterwards, as it deletes the rows directly without loading them, so doesn't know
     * which of the cached objects were deleted. Use deleteByIds() instead to only remove the deleted objects.
     *
     * @param array $wherePairs - column-name/value pairs that the row must have at least one of in order to be
     * deleted. The value in a pair may be an array, to delete any rows that have any one of those values, e.g.
     * id => [1,2,3] would delete rows that have ID 1, 2 or 3. Must not be empty. Use deleteAll() to delete every row.
     *
     * @return void
     *
     * @throws ExceptionMissingRequiredData - if $wherePairs is empty, rather than deleting every row.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    protected function deleteWhereOr(array $wherePairs) : void
    {
        if (count($wherePairs) === 0)
        {
            throw new ExceptionMissingRequiredData(
                "deleteWhereOr() was given no where pairs, which would delete every row in the " .
                "{$this->getTableName()} table. If that is intended, use deleteAll()."
            );
        }

        $query = $this->getDb()->generateDeleteWhereQuery(
            $this->getTable(),
            $wherePairs,
            Conjunction::OR
        );

        $this->getDb()->query($query);
        $this->emptyCache();
    }


    /**
     * Return a closure that hydrates a row of data into this table's row object, by calling the row object's
     * createFromDatabaseRow() method.
     *
     * @return \Closure - the closure takes the data row (and optional field info map from getFieldInfoMap()) and
     * returns the created object, e.g. $object = $rowHydrator($row, $fieldInfoMap);
     */
    protected function getRowHydrator() : \Closure
    {
        $objectClassName = $this->getObjectClassName();

        $rowHydrator = function (
            array $row,
            ?array $fieldInfoMap = null
        ) use ($objectClassName) : AbstractTableRowObject {
            return $objectClassName::createFromDatabaseRow($row, $fieldInfoMap);
        };

        return $rowHydrator;
    }


    /**
     * Get the info about this table's columns, for converting the values of loaded rows to the right PHP types.
     * This is in the same form as pg_meta_data() returns, but it is read from the system catalogs instead, as
     * pg_meta_data() takes the schema and table as one unescaped "schema.table" string, which it splits on the first
     * dot, so it can't find a table whose schema name contains a dot. Here the table is looked up by its escaped,
     * schema-qualified name, so any characters work, and it is always the same table that the queries use.
     *
     * @return array<string, array> - the column info, indexed by column name. Each column has the keys num, type,
     * len, not null, has default, array dims and is enum.
     *
     * @throws ExceptionTableNotFound - if the table doesn't exist, or has no columns.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    protected function getFieldInfoMap() : array
    {
        $db = $this->getDb();
        $escapedQualifiedTableName = $this->getEscapedQualifiedTableName();
        $tableNameLiteral = $db->escapeValue($escapedQualifiedTableName);

        // to_regclass() gives NULL rather than an error if there is no such table, so then no columns are found.
        $query =
            'SELECT a."attname", a."attnum", t."typname", a."attlen", a."attnotnull", a."atthasdef", a."attndims",' .
            ' (t."typtype" = \'e\') AS "is_enum"' .
            ' FROM "pg_catalog"."pg_attribute" a' .
            ' JOIN "pg_catalog"."pg_type" t ON t."oid" = a."atttypid"' .
            " WHERE a.\"attrelid\" = to_regclass({$tableNameLiteral})" .
            ' AND a."attnum" > 0 AND NOT a."attisdropped"' .
            ' ORDER BY a."attnum"';

        $result = $db->query($query);
        $fieldInfoMap = [];

        while (($column = pg_fetch_assoc($result)) !== false)
        {
            $fieldInfoMap[$column['attname']] = [
                'num' => (int)$column['attnum'],
                'type' => $column['typname'],
                'len' => (int)$column['attlen'],
                'not null' => ($column['attnotnull'] === 't'),
                'has default' => ($column['atthasdef'] === 't'),
                'array dims' => (int)$column['attndims'],
                'is enum' => ($column['is_enum'] === 't'),
            ];
        }

        if (count($fieldInfoMap) === 0)
        {
            throw new ExceptionTableNotFound("Failed to find the columns of the {$escapedQualifiedTableName} table.");
        }

        return $fieldInfoMap;
    }


    /**
     * Remove the virtual fields (PostgreSQL generated columns) from a row, so the row can be sent in an insert or
     * update.
     *
     * @param array $row - name value pairs of column to values.
     *
     * @return array - the row without the virtual fields.
     */
    protected function removeVirtualFields(array $row) : array
    {
        return array_diff_key($row, array_flip($this->getVirtualFields()));
    }


    /**
     * Get a row ready to be inserted, for insert() and batchInsert(): remove the virtual fields, and if the row has no
     * ID, generate one in code if this table can do so, otherwise leave the ID out for the database to generate.
     *
     * @param array $row - name value pairs of column to values.
     *
     * @return array - the row to insert.
     *
     * @throws ExceptionMissingRequiredData - if the row has no ID, and neither the code nor the database can
     * generate one.
     */
    private function prepareRowForInsert(array $row) : array
    {
        $row = $this->removeVirtualFields($row);
        $idColumnName = $this->getIdColumnName();

        // prefer generating the ID in code whenever possible.
        if (!isset($row[$idColumnName]))
        {
            if ($this->canCodeGenerateId())
            {
                $row[$idColumnName] = $this->generateId();
            }
            else if ($this->canDatabaseGenerateId())
            {
                unset($row[$idColumnName]); // have the database create the ID.
            }
            else
            {
                throw new ExceptionMissingRequiredData(
                    "No {$idColumnName} was provided for the new {$this->getTableName()} row, and this table can't" .
                    " generate one in code, nor does the database generate one."
                );
            }
        }

        return $row;
    }
}
