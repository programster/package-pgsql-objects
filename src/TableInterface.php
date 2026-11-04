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


interface TableInterface
{

    /**
     * Return the singleton instance of this object.
     */
    public static function getInstance() : TableInterface;


    /**
     * Return the name of this table.
     */
    public function getTableName() : string;


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
     * Get a connection to the database.
     * @return PgSqlConnection
     */
    public function getDb() : PgSqlConnection;


    /**
     * Removes the row with the given ID from the database.
     * @param int|string $id - the ID of the row to delete.
     * @return void
     */
    public function delete(int|string $id) : void;


    /**
     * Deletes all rows from the table by running TRUNCATE.
     */
    public function deleteAll() : void;


    /**
     * Loads all of these objects from the database.
     * @return array<AbstractTableRowObject>
     */
    public function loadAll() : array;


    /**
     * Loads a single object of this class's type from the database using the unique ID of the row.
     * @param string|int $id - the id of the row in the database table.
     * @param bool useCache - optionally set to false to force a database lookup even if we have a
     *                    cached value from a previous lookup.
     * @return AbstractTableRowObject - the loaded object.
     */
    public function load(string|int $id, bool $useCache=true) : AbstractTableRowObject;


    /**
     * Loads a range of rows from the table, in a stable order so that successive calls can page through the table.
     * @param int $offset - the number of rows to skip.
     * @param int $numElements - the maximum number of rows to load.
     * @param string|null $orderByColumn - optionally the name of the column to order by. Defaults to the ID column.
     * @return array<AbstractTableRowObject>
     */
    public function loadRange(int $offset, int $numElements, ?string $orderByColumn = null) : array;


    /**
     * Loads up to $numElements rows whose IDs come after $afterId, in ID order (keyset pagination). Pass null for the
     * first page, then the ID of the last object on each page to load the next.
     * @param string|int|null $afterId - the ID of the last object on the previous page, or null for the first page.
     * @param int $numElements - the maximum number of rows to load.
     * @return array<AbstractTableRowObject>
     */
    public function loadAfterId(string|int|null $afterId, int $numElements) : array;


    /**
     * Create a new row with unfiltered data.
     * @return AbstractTableRowObject
     */
    public function create(array $row) : AbstractTableRowObject;


    /**
     * Update a specified row with inputs
     * @param int|string $id - the current ID of the row being updated.
     * @param array $row - the column/value pairs to update the row with.
     * @return AbstractTableRowObject - the updated model object.
     */
    public function update(int|string $id, array $row) : AbstractTableRowObject;



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
     * @return array<string> - array of column names.
     */
    public function getVirtualFields() : array;


    /**
     * List the fields that should be kept as the string the database returns, rather than being converted to a
     * PHP int/float/bool.
     * @return array<string> - array of column names.
     */
    public function getFieldsToKeepAsString() : array;


    /**
     * Generates an identifier if possible. If you cannot generate an ID, then this should
     * throw an exception. If you can't generate an ID, and expect that the ID is generated
     * in the database, then please throw a \Programster\PgsqlObjects\Exceptions\ExceptionIdGeneratedInDatabase
     * exception.
     * @return mixed - the generated identifier. Implementations should narrow this to their ID type
     *                 (e.g. AbstractUuidTable returns a string).
     * @throws \Programster\PgsqlObjects\Exceptions\ExceptionIdGeneratedInDatabase - if the ID is generated in the
     * database, and cannot be generated here.
     */
    public function generateId() : mixed;


    /**
     * Specify whether generateId() can generate an ID in code. Whenever this is true, new rows get their ID from
     * generateId(), even if the database could also generate one.
     * WARNING - this must be false if the ID column is GENERATED ALWAYS AS IDENTITY, as the database rejects inserts
     * that provide a value for such a column (GENERATED BY DEFAULT AS IDENTITY is fine).
     * @return bool
     */
    public function canCodeGenerateId() : bool;


    /**
     * Specify whether the database can generate the ID, e.g. if the column is SERIAL, an IDENTITY column, or has a
     * default that produces a new value per row, such as gen_random_uuid().
     * This is only relied upon when canCodeGenerateId() is false, in which case new rows without an ID are left for
     * the database to fill in. If both are false, an ID must be provided when creating a row.
     * @return bool
     */
    public function canDatabaseGenerateId() : bool;
}
