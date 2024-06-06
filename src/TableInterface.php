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
     * @return \mysqli
     */
    public function getDb() : PgSqlConnection;


    /**
     * Removes the obeject from the mysql database.
     * @return void
     */
    public function delete($id);


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
     * @param string $id - the id of the row in the datatabase table.
     * @param bool useCache - optionally set to false to force a database lookup even if we have a
     *                    cached value from a previous lookup.
     * @return AbstractTableRowObject - the loaded object.
     */
    public function load(string|int $id, $useCache=true) : AbstractTableRowObject;


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
    public function create(array $row) : AbstractTableRowObject;


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


    /**
     * Generates an identifier when a new one is required.
     * @return mixed - null if one could not be generated.
     */
    public function generateId() : mixed;


    /**
     * Specify whether the ID is generated in the database. This would be the case if using SERIAL etc.
     * @return bool
     */
    public function isIdGeneratedInDatabase() : bool;
}
