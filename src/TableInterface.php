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
     * Get a connection to the database.
     * @return \mysqli
     */
    public function getDb() : PgSqlConnection;


    /**
     * Removes the obeject from the mysql database.
     * @return void
     */
    public function delete(string $uuid);


    /**
     * Deletes all rows from the table by running TRUNCATE.
     */
    public function deleteAll() : void;


    /**
     * Loads all of these objects from the database.
     * @param void
     * @return
     */
    public function loadAll() : array;


    /**
     * Loads a single object of this class's type from the database using the unique ID of the row.
     * @param string $uuid - the id of the row in the datatabase table.
     * @param bool useCache - optionally set to false to force a database lookup even if we have a
     *                    cached value from a previous lookup.
     * @return AbstractTableRowObject - the loaded object.
     */
    public function load(string $uuid, $useCache=true) : AbstractTableRowObject;


    /**
     * Loads a range of data from the table.
     * It is important to note that offset is not tied to ID in any way.
     * @param type $offset
     * @param type $numElements
     * @return array<AbstractTableRowObject>
     */
    public function loadRange(int $offset, int $numElements) : array;


    /**
     * Create a new row with unfiltered data.
     * @return AbstractTableRowObject
     */
    public function create(array $inputs) : AbstractTableRowObject;


    /**
     * Update a specified row with inputs
     * @return AbstractTableRowObject - the updated model object.
     */
    public function update(string $id, array $row) : AbstractTableRowObject;



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
}
