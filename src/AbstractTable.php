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
use Programster\PgsqlObjects\Exceptions\ExceptionObjectDeleted;
use Programster\PgsqlObjects\Exceptions\ExceptionTableNotFound;
use Programster\PgsqlObjects\Exceptions\ExceptionUnknownColumn;
use Programster\PgsqlObjects\Exceptions\ExceptionUnsupportedDatabaseVersion;


/**
 * @template T of \Programster\PgsqlObjects\AbstractTableRowObject
 */
abstract class AbstractTable implements TableInterface
{
    # Array of all the child instances that get created.
    protected static array $s_instances = [];


    # The one object for each row that has been loaded, inserted or saved while the cache is enabled, indexed by ID.
    protected array $m_objectCache = [];


    # Whether the cache is enabled. It is disabled by default (see enableCache()).
    protected bool $m_isCacheEnabled = false;


    # Cache of this table's column info, fetched the first time it's needed (see getFieldInfoMap()). Null until then.
    protected ?array $m_fieldInfoMap = null;


    # The name batchSave() gives each new object's position in the batch, in the rows its insert returns. It's unlikely
    # to clash with a real column.
    private const BATCH_POSITION_COLUMN = '__pgsql_objects_batch_position';


    # The name batchSave() gives the number of each new object's group of columns, in the rows it sends to its insert.
    private const BATCH_GROUP_COLUMN = '__pgsql_objects_batch_group';


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
        return $this->hydrateRow($createdRow, CacheMode::UPDATE_CACHE);
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
        return $this->convertPgResultToObjects($result, CacheMode::UPDATE_CACHE);
    }


    /**
     * Load objects from the table that have ALL of the attributes specified in the provided wherePairs parameter.
     *
     * @param array $wherePairs - column-name/value pairs that the object must have in order to be fetched. The value
     * in a pair may be an array, to load any objects that have any one of those values, e.g. id => [1,2,3] would load
     * objects that have ID 1, 2 or 3. Must not be empty. Use loadAll() to load every row.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled: USE_CACHE returns rows that are already
     * cached as the cached objects, as they are, and caches the rest. UPDATE_CACHE updates the cached objects with the
     * rows' current values, in place, and caches the rest. IGNORE_CACHE returns new objects, and leaves the cache
     * alone. There's no default, so that you choose each time. The query always runs, as the cache is never searched:
     * only the database knows which rows match. So with USE_CACHE, a matching row that's cached is returned as its
     * cached object, whose values (e.g. unsaved changes) may no longer match the filter.
     *
     * @return array<AbstractTableRowObject>
     *
     * @throws ExceptionMissingRequiredData - if $wherePairs is empty.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadWhereAnd(array $wherePairs, CacheMode $cacheMode) : array
    {
        if (count($wherePairs) === 0)
        {
            throw new ExceptionMissingRequiredData(
                "loadWhereAnd() was given no where pairs, which would load every row in the " .
                "{$this->getTableName()} table. If that is intended, use loadAll()."
            );
        }

        $this->assertKnownColumns($wherePairs);

        $query = $this->getDb()->generateSelectWhereQuery(
            $this->getTable(),
            $wherePairs,
            Conjunction::AND
        );

        $result = $this->getDb()->query($query);
        return $this->convertPgResultToObjects($result, $cacheMode);
    }


    /**
     * Load objects from the table that meet ANY of the attributes specified in the provided wherePairs parameter.
     *
     * @param array $wherePairs - column-name/value pairs that the object must have at least one of in order to be
     * fetched. The value in a pair may be an array, to load any objects that have any one of those values, e.g.
     * id => [1,2,3] would load objects that have ID 1, 2 or 3. Must not be empty. Use loadAll() to load every row.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled: USE_CACHE returns rows that are already
     * cached as the cached objects, as they are, and caches the rest. UPDATE_CACHE updates the cached objects with the
     * rows' current values, in place, and caches the rest. IGNORE_CACHE returns new objects, and leaves the cache
     * alone. There's no default, so that you choose each time. The query always runs, as the cache is never searched:
     * only the database knows which rows match. So with USE_CACHE, a matching row that's cached is returned as its
     * cached object, whose values (e.g. unsaved changes) may no longer match the filter.
     *
     * @return array<AbstractTableRowObject>
     *
     * @throws ExceptionMissingRequiredData - if $wherePairs is empty.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadWhereOr(array $wherePairs, CacheMode $cacheMode) : array
    {
        if (count($wherePairs) === 0)
        {
            throw new ExceptionMissingRequiredData(
                "loadWhereOr() was given no where pairs, which would load every row in the " .
                "{$this->getTableName()} table. If that is intended, use loadAll()."
            );
        }

        $this->assertKnownColumns($wherePairs);

        $query = $this->getDb()->generateSelectWhereQuery(
            $this->getTable(),
            $wherePairs,
            Conjunction::OR
        );

        $result = $this->getDb()->query($query); // throws on failure
        return $this->convertPgResultToObjects($result, $cacheMode);
    }


    /**
     * Enable the cache for this table, for the rest of this process or until disableCache() is called. The cache is
     * disabled by default, so that every load gets fresh data from the database, and long-running processes don't
     * build up a cache that goes stale or uses ever more memory. Enable it when it helps, e.g. at the start of a web
     * request, so that a controller can call loadAll() and the views and models it uses can then call load($id)
     * without each one running a query.
     *
     * While it's enabled, there is only ever one object for each row: rows that are inserted or saved are cached, as
     * are rows that are loaded with CacheMode::USE_CACHE or UPDATE_CACHE, and loading a cached row by ID gives back
     * the same object, without a query. Each load's CacheMode says whether it uses the cache, updates it, or ignores
     * it.
     *
     * WARNING - in a server that keeps PHP running between web requests (e.g. FrankenPHP's worker mode, RoadRunner
     * or Swoole), call disableCache() at the end of each request, or the cache carries over into the next one.
     *
     * @return void
     */
    public function enableCache() : void
    {
        $this->m_isCacheEnabled = true;
    }


    /**
     * Disable the cache for this table, and empty it, so that every load queries the database again.
     * @return void
     */
    public function disableCache() : void
    {
        $this->m_isCacheEnabled = false;
        $this->emptyCache();
    }


    /**
     * Completely empty the cache, e.g. to free memory, or after a transaction is rolled back. The cache stays enabled
     * if it was.
     *
     * @return void
     */
    public function emptyCache() : void
    {
        $this->m_objectCache = [];
    }


    /**
     * Make this object the one that is cached for its row, if the cache is enabled. A row object calls this after it
     * has been saved, so that the cache holds the object that was saved, rather than a copy of it.
     *
     * @param AbstractTableRowObject $object - the object, which must have been saved to the database.
     *
     * @return void
     *
     * @throws ExceptionMissingRequiredData - if the object hasn't been saved to the database, as there is then no row
     * for it to be the cached object of.
     */
    public function cacheObject(AbstractTableRowObject $object) : void
    {
        if ($this->m_isCacheEnabled)
        {
            if ($object->getId() === null || $object->isSavedInDatabase() === false)
            {
                throw new ExceptionMissingRequiredData(
                    "Can't cache this " . get_class($object) . ", as it hasn't been saved to the database."
                );
            }

            $this->m_objectCache[$object->getId()] = $object;
        }
    }


    /**
     * Delete the row with the given ID. This doesn't throw if there is no row with the ID. To delete a row object you
     * already have, you can call delete() on the object itself instead. If the row's object is cached, it is marked as
     * deleted (so its save() and update() throw ExceptionObjectDeleted, rather than inserting the row again) and
     * removed from the cache.
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
        $this->forgetDeletedRow($id);
    }


    /**
     * Delete the rows with the given IDs in a single query. This doesn't throw if there are no rows with some of the
     * IDs. An empty list deletes nothing, and runs no query. The cached objects of the deleted rows are marked as
     * deleted (so their save() and update() throw ExceptionObjectDeleted) and removed from the cache.
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
            $this->getDb()->query($this->generateDeleteByIdsQuery($ids));

            foreach ($ids as $id)
            {
                $this->forgetDeletedRow($id);
            }
        }
    }


    /**
     * Delete rows from the table that have ALL of the attributes specified in the provided wherePairs parameter, in a
     * single query. The cached objects of the deleted rows are marked as deleted (so their save() and update() throw
     * ExceptionObjectDeleted) and removed from the cache. Objects of the rows that remain are left in the cache.
     *
     * @param array $wherePairs - column-name/value pairs that the rows must all have in order to be deleted.
     * Must not be empty. Use deleteAll() to delete every row.
     *
     * @return void
     *
     * @throws ExceptionMissingRequiredData - if $wherePairs is empty, rather than deleting every row.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function deleteWhereAnd(array $wherePairs) : void
    {
        if (count($wherePairs) === 0)
        {
            throw new ExceptionMissingRequiredData(
                "deleteWhereAnd() was given no where pairs, which would delete every row in the " .
                "{$this->getTableName()} table. If that is intended, use deleteAll()."
            );
        }

        $this->deleteWhere($wherePairs, Conjunction::AND);
    }


    /**
     * Delete rows from the table that meet ANY of the attributes specified in the provided wherePairs parameter, in a
     * single query. The cached objects of the deleted rows are marked as deleted (so their save() and update() throw
     * ExceptionObjectDeleted) and removed from the cache. Objects of the rows that remain are left in the cache.
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
    public function deleteWhereOr(array $wherePairs) : void
    {
        if (count($wherePairs) === 0)
        {
            throw new ExceptionMissingRequiredData(
                "deleteWhereOr() was given no where pairs, which would delete every row in the " .
                "{$this->getTableName()} table. If that is intended, use deleteAll()."
            );
        }

        $this->deleteWhere($wherePairs, Conjunction::OR);
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
     * Deletes all rows from the table. Every cached object is marked as deleted (so its save() and update() throw
     * ExceptionObjectDeleted) and the cache is emptied. Either way, it can be rolled back if run inside a transaction.
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

        foreach (array_keys($this->m_objectCache) as $cachedId)
        {
            $this->forgetDeletedRow($cachedId);
        }
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
     * @param CacheMode $cacheMode - (optional) how to use the cache, when it's enabled. Defaults to USE_CACHE, which
     * returns the cached object without a query if the row is cached, and otherwise fetches the row and caches it.
     * Use UPDATE_CACHE to fetch the row and update the cached object with its current values, in place, or
     * IGNORE_CACHE to fetch it as a new object without touching the cache.
     *
     * @return T - the loaded object
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException
     */
    public function load(string|int $id, CacheMode $cacheMode = CacheMode::USE_CACHE) : AbstractTableRowObject
    {
        $objects = $this->loadIds([$id], $cacheMode);

        if (count($objects) === 0)
        {
            $msg = "There is no {$this->getObjectClassName()} with {$this->getIdColumnName()}: {$id}";
            throw new ExceptionNoSuchIdException($msg);
        }

        return $objects[array_key_first($objects)];
    }


    /**
     * Loads a number of objects of this table's row type from the database using the provided array
     * list of IDs. By default, when the cache is enabled, the objects that are already cached are returned from there,
     * and only the rest are fetched from the database (in one query) and cached.
     *
     * NOTE: The returned array of objects is indexed by the IDs of the objects.
     *
     * @param array<string|int> $ids - the list of IDs of the objects we wish to load.
     *
     * @param CacheMode $cacheMode - (optional) how to use the cache, when it's enabled. Defaults to USE_CACHE. See
     * load().
     *
     * @return array<string|int, T> - list of the objects with the specified IDs indexed by the objects ID.
     *
     * @psalm-return array<string|int, T>
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadIds(array $ids, CacheMode $cacheMode = CacheMode::USE_CACHE) : array
    {
        $loadedObjects = [];
        $idsToFetch = [];

        foreach ($ids as $id)
        {
            if ($cacheMode === CacheMode::USE_CACHE && isset($this->m_objectCache[$id]))
            {
                $loadedObjects[$id] = $this->m_objectCache[$id];
            }
            else
            {
                $idsToFetch[] = $id;
            }
        }

        if (count($idsToFetch) > 0)
        {
            $escapedIds = $this->getDb()->escapeValues($idsToFetch);

            $query =
                "SELECT * FROM {$this->getEscapedQualifiedTableName()}" .
                " WHERE {$this->getEscapedIdColumnName()} IN(" . implode(", ", $escapedIds) . ")";

            $result = $this->getDb()->query($query);

            foreach ($this->convertPgResultToObjects($result, $cacheMode) as $object)
            {
                $loadedObjects[$object->getId()] = $object;
            }
        }

        return $loadedObjects;
    }


    /**
     * Loads all of these objects from the database. When the cache is enabled, this also caches every row, so that
     * load() can then return any of them without a query.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled: USE_CACHE returns rows that are already
     * cached as the cached objects, as they are, and caches the rest. UPDATE_CACHE updates the cached objects with the
     * rows' current values, in place, and caches the rest. IGNORE_CACHE returns new objects, and leaves the cache
     * alone. There's no default, so that you choose each time. The query always runs, as the cache is never searched:
     * only the database knows which rows match. So with USE_CACHE, a matching row that's cached is returned as its
     * cached object, whose values (e.g. unsaved changes) may no longer match the filter.
     *
     * @return array<AbstractTableRowObject>
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadAll(CacheMode $cacheMode) : array
    {
        $query = "SELECT * FROM {$this->getEscapedQualifiedTableName()}";
        $result = $this->getDb()->query($query);
        return $this->convertPgResultToObjects($result, $cacheMode);
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
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled: USE_CACHE returns rows that are already
     * cached as the cached objects, as they are, and caches the rest. UPDATE_CACHE updates the cached objects with the
     * rows' current values, in place, and caches the rest. IGNORE_CACHE returns new objects, and leaves the cache
     * alone. There's no default, so that you choose each time. The query always runs, as the cache is never searched:
     * only the database knows which rows match. So with USE_CACHE, a matching row that's cached is returned as its
     * cached object, whose values (e.g. unsaved changes) may no longer match the filter.
     *
     * @param string|null $orderByColumn - optionally the name of the column to order by. Defaults to the ID column.
     *
     * @return array<AbstractTableRowObject>
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadRange(
        int $offset,
        int $numElements,
        CacheMode $cacheMode,
        ?string $orderByColumn = null
    ) : array
    {
        $orderByColumns = [$this->getEscapedIdColumnName()];

        if ($orderByColumn !== null && $orderByColumn !== $this->getIdColumnName())
        {
            $this->assertKnownColumns([$orderByColumn => null]);
            array_unshift($orderByColumns, $this->getDb()->escapeIdentifier($orderByColumn));
        }

        $query =
            "SELECT * FROM {$this->getEscapedQualifiedTableName()}" .
            " ORDER BY " . implode(", ", $orderByColumns) .
            " OFFSET {$offset} LIMIT {$numElements}";

        $result = $this->getDb()->query($query); // throws on failure
        return $this->convertPgResultToObjects($result, $cacheMode);
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
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled: USE_CACHE returns rows that are already
     * cached as the cached objects, as they are, and caches the rest. UPDATE_CACHE updates the cached objects with the
     * rows' current values, in place, and caches the rest. IGNORE_CACHE returns new objects, and leaves the cache
     * alone. There's no default, so that you choose each time. The query always runs, as the cache is never searched:
     * only the database knows which rows match. So with USE_CACHE, a matching row that's cached is returned as its
     * cached object, whose values (e.g. unsaved changes) may no longer match the filter.
     *
     * @return array<AbstractTableRowObject>
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadAfterId(string|int|null $afterId, int $numElements, CacheMode $cacheMode) : array
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
        return $this->convertPgResultToObjects($result, $cacheMode);
    }


    /**
     * Update a row specified by the ID with the provided data.
     * The returned object has the values from the row the database returns, so it includes any values the database
     * changed, such as generated columns. When the cache is enabled and the row's object is cached, that object is
     * refreshed and returned, moving to its new ID in the cache if the ID was changed.
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
        $this->assertKnownColumns($row);
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

            # If the ID changed, move the cached object to its new ID, so that it's refreshed below rather than
            # replaced. The IDs are compared as strings, as the database returns integer IDs as strings.
            $isIdChanging = (isset($row[$idColumnName]) && (string)$row[$idColumnName] !== (string)$id);

            if ($isIdChanging && isset($this->m_objectCache[$id]))
            {
                $this->m_objectCache[$row[$idColumnName]] = $this->m_objectCache[$id];
                unset($this->m_objectCache[$id]);
            }

            $updatedObject = $this->hydrateRow($updatedRow, CacheMode::UPDATE_CACHE);
        }
        else
        {
            # nothing that can be written was provided, so just return the row as it is.
            $updatedObject = $this->load($id, CacheMode::UPDATE_CACHE);
        }

        return $updatedObject;
    }


    /**
     * Delete rows from this table whose IDs are not in the provided list. The cached objects of the deleted rows are
     * marked as deleted (so their save() and update() throw ExceptionObjectDeleted) and removed from the cache.
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

        // Forget the deleted rows' cached objects. The IDs are compared as strings, as integer IDs may be cached
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
                $this->forgetDeletedRow($cachedId);
            }
        }
    }


    /**
     * Save many objects in one go: updating the rows of objects that have been saved before, and inserting the rest.
     * Everything is sent to the database as a single query, which runs as one transaction, so either every object is
     * saved, or none are. If a transaction is already open, it all runs within that.
     *
     * New objects whose IDs the database generates (e.g. SERIAL), so that they have no ID yet, are inserted together
     * by a single MERGE ... RETURNING, which returns each one's row, so that it takes on its generated ID, column
     * defaults and generated columns. That needs PostgreSQL 17 or newer. On an older server, a batch with any such
     * objects throws an ExceptionUnsupportedDatabaseVersion, without saving anything, so save them with save(), or
     * insert their rows with batchInsert(), instead. Batches where every object has an ID work on any version.
     *
     * WARNING - the database's values can't be read back for the objects that already have an ID, so their column
     * defaults and generated columns (see getVirtualFields()) will be out of date on them (and in the cache) until they
     * are reloaded with CacheMode::UPDATE_CACHE.
     *
     * @param array<AbstractTableRowObject> $objects - the objects to save.
     *
     * @return void
     *
     * @throws ExceptionObjectDeleted - if an object has been deleted. This is checked before anything is saved.
     *
     * @throws ExceptionUnsupportedDatabaseVersion - if an object has no ID, and the database is older than PostgreSQL
     * 17. This is checked before anything is saved.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if the query failed, in which case none of the
     * objects are saved.
     */
    public function batchSave(array $objects) : void
    {
        if (count($objects) > 0)
        {
            $query = implode(";", $this->generateBatchSaveStatements($objects)) . ";";

            // the statements run as a single implicit transaction, so if any fail, query() throws and none are saved.
            $result = $this->getDb()->query($query);
            $this->takeOnBatchSavedObjects($objects, $result);
        }
    }


    /**
     * Load objects whose IDs were not provided.
     *
     * @param array $ids - the IDs of the rows not to load. If empty, every row is loaded.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled: USE_CACHE returns rows that are already
     * cached as the cached objects, as they are, and caches the rest. UPDATE_CACHE updates the cached objects with the
     * rows' current values, in place, and caches the rest. IGNORE_CACHE returns new objects, and leaves the cache
     * alone. There's no default, so that you choose each time. The query always runs, as the cache is never searched:
     * only the database knows which rows match. So with USE_CACHE, a matching row that's cached is returned as its
     * cached object, whose values (e.g. unsaved changes) may no longer match the filter.
     *
     * @return AbstractTableRowObject[]
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadIdsNotIn(array $ids, CacheMode $cacheMode) : array
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
        return $this->convertPgResultToObjects($result, $cacheMode);
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
     * Helper function that converts a query result into a collection of the row objects, using the cache as
     * $cacheMode says (see CacheMode).
     *
     * @param \Pgsql\Result $result - the result of a query that selected whole rows of this table.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled.
     *
     * @return T[]
     */
    protected function convertPgResultToObjects(\Pgsql\Result $result, CacheMode $cacheMode) : array
    {
        $objects = [];

        while (($row = pg_fetch_assoc($result)) !== false)
        {
            $objects[] = $this->hydrateRow($row, $cacheMode);
        }

        return $objects;
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
     * It is fetched from the database the first time it's needed, then kept for the life of this table object, as
     * the columns don't change while the application is running (migrations are run before the tables are used).
     *
     * @return array<string, array> - the column info, indexed by column name. Each column has the keys num, type,
     * len, not null, has default, array dims and is enum.
     *
     * @throws ExceptionTableNotFound - if the table doesn't exist, or has no columns.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    protected function getFieldInfoMap() : array
    {
        if ($this->m_fieldInfoMap === null)
        {
            $this->m_fieldInfoMap = $this->fetchFieldInfoMap();
        }

        return $this->m_fieldInfoMap;
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
        $this->assertKnownColumns($row);
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


    /**
     * Fetch the info about this table's columns from the database, for getFieldInfoMap() to keep. This is in the same
     * form as pg_meta_data() returns, but it is read from the system catalogs instead, as pg_meta_data() takes the
     * schema and table as one unescaped "schema.table" string, which it splits on the first dot, so it can't find a
     * table whose schema name contains a dot. Here the table is looked up by its escaped, schema-qualified name, so
     * any characters work, and it is always the same table that the queries use.
     *
     * @return array<string, array> - the column info, indexed by column name.
     *
     * @throws ExceptionTableNotFound - if the table doesn't exist, or has no columns.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    private function fetchFieldInfoMap() : array
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
     * Check that every key in the array is one of this table's columns, so that a typo gets a clear exception before
     * any query is run, rather than a less clear error from PostgreSQL. This uses the kept column info, so it doesn't
     * need a query after the first time.
     *
     * @param array $columnValues - column name/value pairs, e.g. a row or where pairs.
     *
     * @return void
     *
     * @throws ExceptionUnknownColumn - if any key isn't one of this table's columns, listing every such key.
     * @throws ExceptionTableNotFound - if the table's columns can't be found.
     */
    private function assertKnownColumns(array $columnValues) : void
    {
        $knownColumns = array_keys($this->getFieldInfoMap());
        $unknownColumns = array_diff(array_keys($columnValues), $knownColumns);

        if (count($unknownColumns) > 0)
        {
            $unknownColumnsList = implode(", ", $unknownColumns);
            $tableName = $this->getEscapedQualifiedTableName();

            throw new ExceptionUnknownColumn(
                "The {$tableName} table has no column(s) called: {$unknownColumnsList}. Its columns are: " .
                implode(", ", $knownColumns) . "."
            );
        }
    }


    /**
     * Get the object for a row of this table, using the cache as $cacheMode says (see CacheMode). While the cache is
     * disabled, or with IGNORE_CACHE, this is always a new object, and the cache isn't touched. Otherwise, a row that
     * is cached gives its cached object (updated with the row's values in place for UPDATE_CACHE, or as it is for
     * USE_CACHE), and a row that isn't is given a new object, which is cached.
     *
     * @param array $row - a whole row of this table, as returned by the database.
     *
     * @param CacheMode $cacheMode - how to use the cache.
     *
     * @return AbstractTableRowObject - the object for the row.
     */
    private function hydrateRow(array $row, CacheMode $cacheMode) : AbstractTableRowObject
    {
        $rowHydrator = $this->getRowHydrator();
        $fieldInfoMap = $this->getFieldInfoMap();
        $newObject = $rowHydrator($row, $fieldInfoMap);
        $id = $newObject->getId();

        if ($this->m_isCacheEnabled === false || $cacheMode === CacheMode::IGNORE_CACHE)
        {
            $object = $newObject;
        }
        elseif (isset($this->m_objectCache[$id]))
        {
            $object = $this->m_objectCache[$id];

            if ($cacheMode === CacheMode::UPDATE_CACHE)
            {
                $this->refreshObject($object, $row, $fieldInfoMap);
            }
        }
        else
        {
            $object = $newObject;
            $this->m_objectCache[$id] = $object;
        }

        return $object;
    }


    /**
     * Refresh an existing object with a row's values, so that everything holding the object sees them.
     *
     * @param AbstractTableRowObject $object - the object to refresh.
     *
     * @param array $row - the row from the database.
     *
     * @param array $fieldInfoMap - the column info, from getFieldInfoMap().
     *
     * @return void
     */
    private function refreshObject(AbstractTableRowObject $object, array $row, array $fieldInfoMap) : void
    {
        // initializeFromArray() is protected, so call it from within the object's scope.
        $refresh = function (array $row, array $fieldInfoMap) : void {
            $this->initializeFromArray($row, $fieldInfoMap);
            $this->m_isSavedInDatabase = true;
        };

        $refresh->call($object, $row, $fieldInfoMap);
    }


    /**
     * Delete the rows that match the where pairs, in a single query, and forget the deleted rows' cached objects (see
     * forgetDeletedRow()). The query returns the deleted rows' IDs, so that only those objects are forgotten.
     *
     * @param array $wherePairs - column-name/value pairs, which the caller has checked aren't empty.
     *
     * @param Conjunction $conjunction - whether a row must match ALL of the pairs, or ANY of them.
     *
     * @return void
     *
     * @throws ExceptionUnknownColumn - if a where pair's key isn't one of this table's columns.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    private function deleteWhere(array $wherePairs, Conjunction $conjunction) : void
    {
        $this->assertKnownColumns($wherePairs);
        $query = $this->getDb()->generateDeleteWhereQuery($this->getTable(), $wherePairs, $conjunction);
        $result = $this->getDb()->query($query . " RETURNING {$this->getEscapedIdColumnName()}");
        $idColumnName = $this->getIdColumnName();

        while (($deletedRow = pg_fetch_assoc($result)) !== false)
        {
            $this->forgetDeletedRow($deletedRow[$idColumnName]);
        }
    }


    /**
     * Forget the cached object of a row that has been deleted: mark it as deleted, so that its save() and update()
     * throw ExceptionObjectDeleted rather than inserting the row again, and remove it from the cache. Does nothing if
     * the row's object isn't cached.
     *
     * @param int|string $id - the ID of the deleted row.
     *
     * @return void
     */
    private function forgetDeletedRow(int|string $id) : void
    {
        if (isset($this->m_objectCache[$id]))
        {
            $this->markObjectDeleted($this->m_objectCache[$id]);
            unset($this->m_objectCache[$id]);
        }
    }


    /**
     * Mark an object as deleted, so that its save() and update() throw ExceptionObjectDeleted rather than inserting
     * its row again.
     *
     * @param AbstractTableRowObject $object - the object whose row has been deleted.
     *
     * @return void
     */
    private function markObjectDeleted(AbstractTableRowObject $object) : void
    {
        // the deleted flag is protected, so set it from within the object's scope.
        $markDeleted = function () : void {
            $this->m_isDeleted = true;
            $this->m_isSavedInDatabase = false;
        };

        $markDeleted->call($object);
    }


    /**
     * Generate the SQL statements that save the objects for batchSave() (and Transaction), without running them or
     * changing the objects: the update or insert of each object that has an ID, followed by one insert of the objects
     * without IDs, whose rows its result returns (see generateBatchInsertObjectsQuery()). Every object is checked
     * first, so if one is rejected, nothing has been sent to the database. Pass the result of the last statement to
     * takeOnBatchSavedObjects().
     *
     * @param array<AbstractTableRowObject> $objects - the objects to save, of this table. Must not be empty.
     *
     * @return array<string> - the statements, in order, each a single SQL statement without a trailing semicolon.
     *
     * @throws ExceptionObjectDeleted - if an object has been deleted.
     *
     * @throws ExceptionUnsupportedDatabaseVersion - if an object has no ID, and the database is older than PostgreSQL
     * 17.
     */
    private function generateBatchSaveStatements(array $objects) : array
    {
        $objectsWithIds = [];
        $objectsWithoutIds = [];

        foreach ($objects as $object)
        {
            if ($object->isDeleted())
            {
                $className = get_class($object);
                throw new ExceptionObjectDeleted("Can't batch save this {$className}, as it has been deleted.");
            }

            if ($object->getId() === null)
            {
                $objectsWithoutIds[] = $object;
            }
            else
            {
                $objectsWithIds[] = $object;
            }
        }

        $statements = [
            // dont check for uniqueness until the end of the transaction to resolve issues with reorgnizing sort
            // indexes https://www.postgresql.org/docs/9.1/sql-set-constraints.html
            "SET CONSTRAINTS ALL DEFERRED"
        ];

        foreach ($objectsWithIds as $object)
        {
            $statements[] = $object->getSaveQuery();
        }

        // the insert of the objects without IDs goes last, as only the last statement's result is returned.
        if (count($objectsWithoutIds) > 0)
        {
            $this->assertDatabaseSupportsMergeReturning();
            $statements[] = $this->generateBatchInsertObjectsQuery($objectsWithoutIds);
        }

        return $statements;
    }


    /**
     * Mark the objects from generateBatchSaveStatements() as saved, once its statements have run (and, in a
     * Transaction, been committed), giving each object that had no ID its inserted row, and caching them if the cache
     * is enabled.
     *
     * @param array<AbstractTableRowObject> $objects - the objects that were saved, as passed to
     * generateBatchSaveStatements().
     *
     * @param \PgSql\Result $result - the result of the last of those statements.
     *
     * @return void
     */
    private function takeOnBatchSavedObjects(array $objects, \PgSql\Result $result) : void
    {
        $objectsWithoutIds = [];

        foreach ($objects as $object)
        {
            if ($object->getId() === null)
            {
                $objectsWithoutIds[] = $object;
            }
            else
            {
                $object->markSaved(); // just in case it was an insert instead of update call.
                $this->cacheObject($object);
            }
        }

        if (count($objectsWithoutIds) > 0)
        {
            $this->takeOnInsertedRows($objectsWithoutIds, $result);
        }
    }


    /**
     * Generate the query that deletes the rows with the given IDs, without running it. It's a single SQL statement.
     *
     * @param array<int|string> $ids - the IDs of the rows to delete. Must not be empty, as an empty IN() is invalid.
     *
     * @return string
     */
    private function generateDeleteByIdsQuery(array $ids) : string
    {
        $escapedIds = $this->getDb()->escapeValues($ids);

        $query =
            "DELETE FROM {$this->getEscapedQualifiedTableName()}" .
            " WHERE {$this->getEscapedIdColumnName()} IN(" . implode(", ", $escapedIds) . ")";

        return $query;
    }


    /**
     * Mark the objects a Transaction deleted as deleted, once it has been committed, and forget their rows' cached
     * objects, just as their delete() would.
     *
     * @param array<AbstractTableRowObject> $objects - the deleted objects, of this table.
     *
     * @return void
     */
    private function takeOnDeletedObjects(array $objects) : void
    {
        foreach ($objects as $object)
        {
            if ($object->getId() !== null)
            {
                $this->forgetDeletedRow($object->getId());
            }

            $this->markObjectDeleted($object);
        }
    }


    /**
     * Generate the query that inserts new objects that have no ID yet, for batchSave(). It's a MERGE, as that can
     * return each inserted row along with the position of the object it came from in $objects (from WITH ORDINALITY),
     * which a plain INSERT ... RETURNING can't, as it doesn't promise to return the rows in any particular order. The
     * objects' values are sent as JSON, which jsonb_populate_record() turns into rows of this table's type, so that
     * every value has its column's type.
     *
     * Objects don't all have to have the same columns, e.g. a row class may leave out a column it has no value for, so
     * that the column gets its database default. So the objects are grouped by their columns, and each group gets its
     * own INSERT, which only inserts those columns, just as insert() would for each object on its own.
     *
     * @param array<AbstractTableRowObject> $objects - the objects to insert, as a list.
     *
     * @return string - the query.
     *
     * @throws ExceptionUnknownColumn - if an object has a column this table doesn't.
     * @throws \JsonException - if a value can't be encoded as JSON, e.g. INF, NAN or a string that isn't UTF-8.
     */
    private function generateBatchInsertObjectsQuery(array $objects) : string
    {
        $db = $this->getDb();

        // getDatabaseArrayForm() is protected, so call it from within each object's scope.
        $getDatabaseArrayForm = function () : array { return $this->getDatabaseArrayForm(); };
        $elements = [];
        $groupNumbersByColumns = [];
        $columnsByGroupNumber = [];

        foreach ($objects as $object)
        {
            $row = $getDatabaseArrayForm->call($object);
            $this->assertKnownColumns($row);
            $columnNames = array_keys($row);
            sort($columnNames);
            $groupKey = implode(",", $columnNames);

            if (!isset($groupNumbersByColumns[$groupKey]))
            {
                $groupNumbersByColumns[$groupKey] = count($columnsByGroupNumber);
                $columnsByGroupNumber[] = $columnNames;
            }

            $elements[] = ['group' => $groupNumbersByColumns[$groupKey], 'row' => $row];
        }

        $group = $db->escapeIdentifier(self::BATCH_GROUP_COLUMN);
        $whenClauses = [];

        foreach ($columnsByGroupNumber as $groupNumber => $columnNames)
        {
            $whenClauses[] = " WHEN NOT MATCHED AND \"source\".{$group} = {$groupNumber} THEN " .
                $this->generateMergeInsert($columnNames);
        }

        $tableName = $this->getEscapedQualifiedTableName();
        $position = $db->escapeIdentifier(self::BATCH_POSITION_COLUMN);
        $elementsLiteral = $db->escapeValue($this->encodeJson($elements));

        $query =
            "MERGE INTO {$tableName} AS \"target\"" .
            " USING (" .
                "SELECT \"element\".{$position}," .
                " (\"element\".\"value\" ->> 'group')::int AS {$group}," .
                " \"row\".*" .
                " FROM jsonb_array_elements({$elementsLiteral}::jsonb)" .
                " WITH ORDINALITY AS \"element\"(\"value\", {$position})" .
                " CROSS JOIN LATERAL" .
                " jsonb_populate_record(NULL::{$tableName}, \"element\".\"value\" -> 'row') AS \"row\"" .
            ") AS \"source\"" .
            " ON FALSE" .
            implode("", $whenClauses) .
            " RETURNING \"source\".{$position}, \"target\".*";

        return $query;
    }


    /**
     * Generate the INSERT part of a MERGE clause, inserting the given columns from the source row.
     *
     * @param array<string> $columnNames - the names of the columns to insert. If empty, every column gets its default.
     *
     * @return string - e.g. INSERT ("title") VALUES ("source"."title").
     */
    private function generateMergeInsert(array $columnNames) : string
    {
        if (count($columnNames) > 0)
        {
            $escapedColumnNames = $this->getDb()->escapeIdentifiers($columnNames);
            $sourceColumns = [];

            foreach ($escapedColumnNames as $escapedColumnName)
            {
                $sourceColumns[] = "\"source\".{$escapedColumnName}";
            }

            $columnsList = implode(", ", $escapedColumnNames);
            $insert = "INSERT ({$columnsList}) VALUES (" . implode(", ", $sourceColumns) . ")";
        }
        else
        {
            $insert = "INSERT DEFAULT VALUES";
        }

        return $insert;
    }


    /**
     * Give each new object from batchSave() its inserted row, so that it takes on its generated ID, column defaults and
     * generated columns, and is marked as saved, and cached if the cache is enabled.
     *
     * @param array<AbstractTableRowObject> $objects - the objects that were inserted, in the order they were sent in.
     *
     * @param \PgSql\Result $result - the result of the query from generateBatchInsertObjectsQuery().
     *
     * @return void
     */
    private function takeOnInsertedRows(array $objects, \PgSql\Result $result) : void
    {
        $fieldInfoMap = $this->getFieldInfoMap();

        while (($row = pg_fetch_assoc($result)) !== false)
        {
            // WITH ORDINALITY counts from 1.
            $object = $objects[(int)$row[self::BATCH_POSITION_COLUMN] - 1];
            unset($row[self::BATCH_POSITION_COLUMN]);
            $this->refreshObject($object, $row, $fieldInfoMap);
            $this->cacheObject($object);
        }
    }


    /**
     * Check that the database supports MERGE ... RETURNING, which batchSave() needs to insert objects without IDs. The
     * version comes from the connection, so this doesn't need a query.
     *
     * @return void
     *
     * @throws ExceptionUnsupportedDatabaseVersion - if the database is older than PostgreSQL 17.
     */
    private function assertDatabaseSupportsMergeReturning() : void
    {
        // the server's version, e.g. "18.6 (Debian 18.6-1)". Not the client library's, which may differ.
        $version = pg_version($this->getDb()->getResource());
        $serverVersion = isset($version['server']) ? $version['server'] : 'unknown';
        $majorVersion = (int)explode('.', $serverVersion)[0];

        if ($majorVersion < 17)
        {
            throw new ExceptionUnsupportedDatabaseVersion(
                "batchSave() can only save new {$this->getTableName()} objects that don't have IDs yet (as the" .
                " database generates them) on PostgreSQL 17 or newer, but the database is version {$serverVersion}." .
                " Save those objects with save(), or insert their rows with batchInsert(), instead."
            );
        }
    }


    /**
     * Encode the rows as JSON, keeping every float exact, regardless of the serialize_precision setting.
     *
     * @param array $rows - the rows to encode.
     *
     * @return string - the JSON.
     *
     * @throws \JsonException - if a value can't be encoded.
     */
    private function encodeJson(array $rows) : string
    {
        // with serialize_precision set to -1, floats are encoded as the shortest string that converts back to exactly
        // the same float. -1 is the default, but set it for this call in case the application has changed it.
        $previousPrecision = ini_set('serialize_precision', '-1');

        try
        {
            $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        finally
        {
            if ($previousPrecision !== false)
            {
                ini_set('serialize_precision', $previousPrecision);
            }
        }

        return $json;
    }


    # Accessors
    public function isCacheEnabled() : bool { return $this->m_isCacheEnabled; }
}
