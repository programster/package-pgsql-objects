<?php

/**
 * The table handler is an object for interfacing with a table rather than a row.
 * This can be returned from a ModelObject from a static method. Thus if the programmer wants
 * to fetch a resource using the ModelObject definition, they would do:
 * MyModelName::getTableHandler()->load($id);
 * This is allows the developer to treat the model as an object that represents a row in the table
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlLib\Table;


interface TableInterface
{

    /**
     * Return the singleton instance of this object.
     */
    public static function getInstance() : TableInterface;


    /**
     * Get the plain (unescaped) name of this table on its own, without the schema, e.g. user.
     * Use getEscapedQualifiedTableName() when putting the table into a query.
     *
     * @return string
     */
    public function getTableName() : string;


    /**
     * Get the plain (unescaped) name of the schema this table is in, e.g. public.
     * Use getEscapedQualifiedTableName() when putting the table into a query.
     *
     * @return string
     */
    public function getSchemaName() : string;


    /**
     * Get the escaped, schema-qualified name of this table, ready for use in a query, e.g. "public"."user". This is
     * always used rather than the bare table name, so that queries use the table in the correct schema rather than
     * relying on the connection's search_path.
     *
     * @return string
     */
    public function getEscapedQualifiedTableName() : string;


    /**
     * Get this table as a pgsql-lib Table, in its schema, for passing to the pgsql-lib query generators.
     * @return Table
     */
    public function getTable() : Table;


    /**
     * Enable the cache for this table. It is disabled by default. While it's enabled, there is only one object for
     * each row, and loading a row again returns that object without a query.
     *
     * @return void
     */
    public function enableCache() : void;


    /**
     * Disable the cache for this table, and empty it.
     * @return void
     */
    public function disableCache() : void;


    /**
     * Check whether the cache is enabled for this table.
     * @return bool
     */
    public function isCacheEnabled() : bool;


    /**
     * Empty the cache, leaving it enabled if it was.
     * @return void
     */
    public function emptyCache() : void;


    /**
     * Make this saved object the one that is cached for its row, if the cache is enabled. Records call this after
     * they have been saved.
     *
     * @param AbstractRecord $object - the object, which must have been saved to the database.
     *
     * @return void
     */
    public function cacheObject(AbstractRecord $object) : void;


    /**
     * Specifies the name of the identifier column. This will commonly be "id" or "uuid".
     * @return string
     */
    public function getIdColumnName() : string;


    /**
     * Get the escaped form of the name of the column that acts as the identifier.
     * @return string
     */
    public function getEscapedIdColumnName() : string;


    /**
     * Check that a value can be one of this table's IDs, and return it in the form the database stores it, so it
     * matches the cache and the IDs of loaded records. Every method that takes an ID calls this before any query runs.
     *
     * @param int|string $id - the ID to check.
     *
     * @return int|string - the ID, in its standard form.
     *
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionInvalidId - if the value can't be one of this table's IDs.
     */
    public function normalizeId(int|string $id) : int|string;


    /**
     * Get a connection to the database.
     * @return PgSqlConnection
     */
    public function getDb() : PgSqlConnection;


    /**
     * Removes the row with the given ID from the database.
     * @param int|string $id - the ID of the row to delete.
     * @return void
     */
    public function deleteById(int|string $id) : void;


    /**
     * Deletes all rows from the table, using DELETE, or TRUNCATE if $useTruncate is true.
     *
     * @param bool $useTruncate - (optional) set to true to run TRUNCATE, which is faster on a large table, but fails
     * if another table has a foreign key referencing this one, skips ON DELETE triggers, and locks the whole table.
     * Defaults to false, which runs DELETE.
     *
     * @return void
     */
    public function deleteAll(bool $useTruncate = false) : void;


    /**
     * Delete the rows with the given IDs in a single query. IDs with no row are ignored, and an empty list deletes
     * nothing. The deleted rows' cached objects are marked as deleted and removed from the cache.
     *
     * @param array<int|string> $ids - the IDs of the rows to delete.
     *
     * @return void
     */
    public function deleteByIds(array $ids) : void;


    /**
     * Delete the rows whose IDs are not in the given list. The deleted rows' cached objects are marked as deleted and
     * removed from the cache.
     *
     * @param array<int|string> $ids - the IDs of the only rows to keep. Must not be empty, as that would delete every
     * row. Use deleteAll() to delete every row.
     *
     * @return void
     *
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData - if $ids is empty.
     */
    public function deleteIdsNotIn(array $ids) : void;


    /**
     * Delete the rows that have ALL of the given column/value pairs. The deleted rows' cached objects are marked as
     * deleted and removed from the cache.
     *
     * @param array $wherePairs - the column/value pairs a row must all have to be deleted. Must not be empty. Use
     * deleteAll() to delete every row.
     *
     * @return void
     *
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData - if $wherePairs is empty.
     */
    public function deleteWhereAnd(array $wherePairs) : void;


    /**
     * Delete the rows that have ANY of the given column/value pairs. The deleted rows' cached objects are marked as
     * deleted and removed from the cache.
     *
     * @param array $wherePairs - the column/value pairs a row must have at least one of to be deleted. A value may be
     * an array, to match any of those values, e.g. id => [1, 2, 3]. Must not be empty. Use deleteAll() to delete every
     * row.
     *
     * @return void
     *
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData - if $wherePairs is empty.
     */
    public function deleteWhereOr(array $wherePairs) : void;


    /**
     * Loads all of these objects from the database.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled (see CacheMode). There's no default, so
     * that you choose each time.
     *
     * @return array<AbstractRecord>
     */
    public function loadAll(CacheMode $cacheMode) : array;


    /**
     * Loads a single object of this class's type from the database using the unique ID of the row.
     *
     * @param string|int $id - the id of the row in the database table.
     *
     * @param CacheMode $cacheMode - (optional) how to use the cache, when it's enabled (see CacheMode). Defaults to
     * USE_CACHE, which returns the cached object without a query if the row is cached.
     *
     * @return AbstractRecord - the loaded object.
     */
    public function load(string|int $id, CacheMode $cacheMode = CacheMode::USE_CACHE) : AbstractRecord;


    /**
     * Loads a range of rows from the table, in a stable order so that successive calls can page through the table.
     *
     * @param int $offset - the number of rows to skip.
     *
     * @param int $numElements - the maximum number of rows to load.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled (see CacheMode). There's no default.
     *
     * @param string|null $orderByColumn - optionally the name of the column to order by. Defaults to the ID column.
     *
     * @return array<AbstractRecord>
     */
    public function loadRange(
        int $offset,
        int $numElements,
        CacheMode $cacheMode,
        ?string $orderByColumn = null
    ) : array;


    /**
     * Loads up to $numElements rows whose IDs come after $afterId, in ID order (keyset pagination). Pass null for the
     * first page, then the ID of the last object on each page to load the next.
     *
     * @param string|int|null $afterId - the ID of the last object on the previous page, or null for the first page.
     *
     * @param int $numElements - the maximum number of rows to load.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled (see CacheMode). There's no default.
     *
     * @return array<AbstractRecord>
     */
    public function loadAfterId(string|int|null $afterId, int $numElements, CacheMode $cacheMode) : array;


    /**
     * Load the objects with the given IDs. With the default USE_CACHE, objects that are already cached are returned
     * from the cache, and only the rest are fetched, in one query.
     *
     * @param array<int|string> $ids - the IDs of the objects to load.
     *
     * @param CacheMode $cacheMode - (optional) how to use the cache, when it's enabled (see CacheMode). Defaults to
     * USE_CACHE.
     *
     * @return array<int|string, AbstractRecord> - the loaded objects, indexed by their IDs.
     */
    public function loadIds(array $ids, CacheMode $cacheMode = CacheMode::USE_CACHE) : array;


    /**
     * Load the objects whose IDs are not in the given list.
     *
     * @param array<int|string> $ids - the IDs of the rows not to load. If empty, every row is loaded.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled (see CacheMode). There's no default.
     *
     * @return array<AbstractRecord>
     */
    public function loadIdsNotIn(array $ids, CacheMode $cacheMode) : array;


    /**
     * Load the objects whose rows have ALL of the given column/value pairs. The query always runs, as the cache is
     * never searched by anything but ID.
     *
     * @param array $wherePairs - the column/value pairs a row must all have to be loaded. A value may be an array, to
     * match any of those values, e.g. id => [1, 2, 3]. Must not be empty. Use loadAll() to load every row.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled (see CacheMode). There's no default.
     *
     * @return array<AbstractRecord>
     *
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData - if $wherePairs is empty.
     */
    public function loadWhereAnd(array $wherePairs, CacheMode $cacheMode) : array;


    /**
     * Load the objects whose rows have ANY of the given column/value pairs. The query always runs, as the cache is
     * never searched by anything but ID.
     *
     * @param array $wherePairs - the column/value pairs a row must have at least one of to be loaded. A value may be
     * an array, to match any of those values, e.g. id => [1, 2, 3]. Must not be empty. Use loadAll() to load every
     * row.
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled (see CacheMode). There's no default.
     *
     * @return array<AbstractRecord>
     *
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData - if $wherePairs is empty.
     */
    public function loadWhereOr(array $wherePairs, CacheMode $cacheMode) : array;


    /**
     * Insert a new row into the table, and return the object for it.
     * @param array $row - the column/value pairs to insert.
     * @return AbstractRecord - the object for the inserted row, including any values the database generated.
     */
    public function insert(array $row) : AbstractRecord;


    /**
     * Insert many rows into the table in a single query, and return the objects for them, including any values the
     * database generated, such as their IDs. The objects are not necessarily in the same order as $rows.
     *
     * @param array<array> $rows - the rows to insert, as column/value pairs. Must not be empty.
     *
     * @return array<AbstractRecord> - the objects for the inserted rows.
     */
    public function batchInsert(array $rows) : array;


    /**
     * Save many objects in one go, as a single query that runs as one transaction, so either every object is saved,
     * or none are: the rows of objects that have been saved before are updated, and the rest are inserted.
     *
     * New objects without IDs, as the database generates them, need PostgreSQL 17 or newer. Every object takes on its
     * row from the database afterwards, including column defaults and generated columns.
     *
     * @param array<AbstractRecord> $objects - the objects to save.
     *
     * @return void
     *
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionObjectDeleted - if an object has been deleted.
     *
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionUnsupportedDatabaseVersion - if an object has no ID, and
     * the database is older than PostgreSQL 17.
     */
    public function batchSave(array $objects) : void;


    /**
     * Update a specified row with inputs
     * @param int|string $id - the current ID of the row being updated.
     * @param array $row - the column/value pairs to update the row with.
     * @return AbstractRecord - the updated model object.
     */
    public function updateById(int|string $id, array $row) : AbstractRecord;


    /**
     * List the fields that allow null values
     * @return array<string> - array of column names.
     */
    public function getFieldsThatAllowNull() : array;


    /**
     * List the fields that have default values.
     * @return array<string> - array of column names.
     */
    public function getFieldsThatHaveDefaults() : array;


    /**
     * List the virtual fields. These are columns whose values PostgreSQL always computes itself, i.e. PostgreSQL
     * generated columns (GENERATED ALWAYS AS (...), both STORED and VIRTUAL), or non-ID GENERATED ALWAYS AS IDENTITY
     * columns. This is unrelated to generating IDs, as this package never generates these values itself. These are
     * never sent to the database in inserts or updates, as PostgreSQL rejects any value for them.
     *
     * @return array<string> - array of column names.
     */
    public function getVirtualFields() : array;


    /**
     * List the fields that should be kept as the string the database returns, rather than being converted to a
     * PHP int/float/bool.
     *
     * @return array<string> - array of column names.
     */
    public function getFieldsToKeepAsString() : array;


    /**
     * Generates an identifier if possible. If you cannot generate an ID, then this should
     * throw an exception. If you can't generate an ID, and expect that the ID is generated
     * in the database, then please throw a \Programster\PgsqlObjects\Exceptions\ExceptionIdGeneratedInDatabase
     * exception.
     *
     * @return int|string - the generated identifier. Implementations should narrow this to their ID type (e.g.
     * AbstractUuidTable returns a string), or declare never if they always throw.
     *
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionIdGeneratedInDatabase - if the ID is generated in the
     * database, and cannot be generated here.
     */
    public function generateId() : int|string;


    /**
     * Specify whether generateId() can generate an ID in code. Whenever this is true, new rows get their ID from
     * generateId(), even if the database could also generate one.
     * WARNING - this must be false if the ID column is GENERATED ALWAYS AS IDENTITY, as the database rejects inserts
     * that provide a value for such a column (GENERATED BY DEFAULT AS IDENTITY is fine).
     *
     * @return bool
     */
    public function canCodeGenerateId() : bool;


    /**
     * Specify whether the database can generate the ID, e.g. if the column is SERIAL, an IDENTITY column, or has a
     * default that produces a new value per row, such as gen_random_uuid().
     * This is only relied upon when canCodeGenerateId() is false, in which case new rows without an ID are left for
     * the database to fill in. If both are false, an ID must be provided when creating a row.
     *
     * @return bool
     */
    public function canDatabaseGenerateId() : bool;
}
