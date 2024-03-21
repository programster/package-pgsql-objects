<?php

/*
 * A class to represent a table in the database. Use this for the loading/deleting of objects/rows.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;

use Programster\PgsqlLib\Conjunction;
use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData;


abstract class AbstractTable implements TableInterface
{
    # Array of all the child instances that get created.
    protected static array $s_instances;


    # Cache of loaded objects so we don't need to go and re-fetch them.
    # This object needs to ensure we clear these when we update rows.
    protected $m_objectCache = array();


    # private constructor so that one has to use the getInstance method.
    protected function __construct()
    {

    }


    /**
     * Fetch the single instance of this object.
     * @return AbstractTable
     */
    public static function getInstance() : static
    {
        $className = get_called_class();

        if (!isset(self::$s_instances[$className]))
        {
            self::$s_instances[$className] = new $className();
        }

        return self::$s_instances[$className];
    }


    /**
     * Helper function that converts a query result into a collection of the row objects.
     * @param \Pgsql\Result $result
     * @return array<AbstractTableRowObject>
     */
    protected function convertPgResultToObjects(\Pgsql\Result $result) : array
    {
        $objects = array();

        if (pg_num_rows($result) > 0)
        {
            $fieldInfoMap = pg_meta_data($this->getDb()->getResource(), $this->getTableName());
            $constructor = $this->getRowObjectConstructorWrapper();

            while (($row = pg_fetch_assoc($result)) != null)
            {
                $loadedObject = $constructor($row, $fieldInfoMap);
                $this->updateCache($loadedObject);
                $objects[] = $loadedObject;
            }
        }

        return $objects;
    }


    /**
     * Fetch an object from our cache.
     * @param string $uuid - the id of the row the object represents.
     * @return AbstractTableRowObject
     */
    protected function getCachedObject(string $uuid)
    {
        if (!isset($this->m_objectCache[$uuid]))
        {
            throw new Exceptions\ExceptionNoSuchIdException("There is no cached object");
        }

        return $this->m_objectCache[$uuid];
    }


    /**
     * Create a new object that represents a new row in the database.
     * @param array $row - name value pairs to create the object from.
     * @return AbstractTableRowObject
     */
    public function create(array $row) : AbstractTableRowObject
    {
        if (array_key_exists($this->getIdColumnName(), $row))
        {
            // id column is set, but may be null
            if ($row[$this->getIdColumnName()] === null)
            {
                if ($this->isIdGeneratedInDatabase())
                {
                    unset($row[$this->getIdColumnName()]); // have the database create the ID.
                }
                else
                {
                    $row[$this->getIdColumnName()] = $this->generateId();
                }
            }
        }
        else
        {
            if ($this->isIdGeneratedInDatabase() === false)
            {
                $row[$this->getIdColumnName()] = $this->generateId();
            }
        }

        // user may decide not to set the uuid themselves, in which case we create it for them.
        if (!isset($row[$this->getIdColumnName()]))
        {
            if ($this->isIdGeneratedInDatabase() === false)
            {
                $row[$this->getIdColumnName()] = $this->generateId();
            }
        }


        $columnNames = array_keys($row);
        $values = array_values($row);
        $escapedColumnNames = $this->getDb()->escapeIdentifiers($columnNames);
        $escapedValues = $this->getDb()->escapeValues($values);

        $valuesString = "";

        foreach ($escapedValues as $escapedValue)
        {
            if ($escapedValue === null)
            {
                $valuesString .= "NULL, ";
            }
            else
            {
                $valuesString .= $escapedValue . ", ";
            }
        }

        $valuesString = \Safe\substr($valuesString, 0, strlen($valuesString) - 2);

        $query =
            "INSERT INTO {$this->getEscapedTableName()}" .
            " (" . implode(",", $escapedColumnNames) . ")" .
            " VALUES ($valuesString)";

        $this->getDb()->query($query);
        $constructor = $this->getRowObjectConstructorWrapper();
        $object = $constructor($row);
        $this->updateCache($object);
        return $object;
    }

    /**
     * Delete rows from the table that meet have all the attributes specified
     * in the provided wherePairs parameter.
     * This will actually run a selection based on the filter to select the objects before deleting them
     * so that we can update the cache accordingly.
     * @throws \Exception
     */
    protected function deleteWhereAnd(array $wherePairs, $updateCache=true) : void
    {
        $objects = $this->loadWhereAnd($wherePairs);
        $objectIds = array();

        foreach ($objects as $object)
        {
            /* @var $object AbstractTableRowObject */
            $objectIds[] = $object->getId();
        }

        $this->deleteByIds(...$objectIds);
    }


    /**
     * Update our cache with the provided object.
     * Note that if you simply changed the object's ID, you will need to call unsetCache() on
     * the original ID.
     * @param AbstractTableRowObject $object
     * @return void
     */
    protected function updateCache(AbstractTableRowObject $object) : void
    {
        $this->m_objectCache[$object->getId()] = $object;
    }


    /**
     * Load objects from the table that meet have all the attributes specified
     * in the provided wherePairs parameter.
     * @param array $wherePairs - column-name/value pairs that the object must have in order
     *                            to be fetched. the value in the pair may be an array to load
     *                            any objects that have any one of those falues.
     *                            For example:
     *                              id => array(1,2,3) would load objects that have ID 1,2, or 3.
     * @return array<AbstractTableRowObject>
     * @throws \Exception
     */
    public function loadWhereAnd(array $wherePairs) : array
    {
        $query = PgsqlLib::generateSelectWhereQuery(
            $this->getDb()->getResource(),
            $this->getTableName(),
            $wherePairs,
            Conjunction::AND
        );

        $result = $this->getDb()->query($query);
        return $this->convertPgResultToObjects($result);
    }


    /**
     * Load objects from the table that meet meet ANY of the attributes specified
     * in the provided wherePairs parameter.
     * @param array $wherePairs - column-name/value pairs that the object must have at least one of
     *                            in order to be fetched. the value in the pair may be an array to
     *                            load any objects that have any one of those falues.
     *                            For example:
     *                              id => array(1,2,3) would load objects that have ID 1,2, or 3.
     * @return array<AbstractTableRowObject>
     * @throws \Exception
     */
    public function loadWhereOr(array $wherePairs) : array
    {
        $query = $this->getDb()->generateSelectWhereQuery(
            $this->getTableName(),
            $wherePairs,
        Conjunction::OR
        );

        $result = $this->getDb()->query($query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to load objects, check your where parameters.");
        }

        return $this->convertPgResultToObjects($result);
    }


    /**
     * Deletes objects that have the any of the specified IDs. This will not throw an error or
     * exception if an object with one of the IDs specified does not exist.
     * This is a fast and cache-friendly operation.
     * @param array $ids - the list of IDs of the objects we wish to delete.
     * @return int - the number of objects deleted.
     */
    public function deleteIds(array $ids) : void
    {
        $idsToDelete = $this->getDb()->escapeValues($ids);
        $wherePairs = array($this->getIdColumnName() => $idsToDelete);

        $query = PgsqlLib::generateDeleteWhereQuery(
            $this->getDb(),
            $this->getTableName(),
            $wherePairs,
            Conjunction::AND
        );

        $result = $this->getDb()->query($query);

        if ($result == FALSE)
        {
            throw new \Exception("Failed to delete objects by their identifiers.");
        }

        # Remove these objects from our cache.
        foreach ($ids as $objectId)
        {
            $this->unsetCache($objectId);
        }
    }


    /**
     * Delete rows from the table that meet meet ANY of the attributes specified
     * in the provided wherePairs parameter.
     * WARNING - by default this will clear your cache. You can manually set clearCache to false
     *           if you know what you are doing, but you may wish to delete by ID instead which
     *           will be cache-optimised. We clear the cache to prevent loading cached objects
     *           from memory when they were previously deleted using one of these methods.
     * @param array $wherePairs - column-name/value pairs that the object must have at least one of
     *                            in order to be fetched. the value in the pair may be an array to
     *                            delete any objects that have any one of those falues.
     *                            For example:
     *                              id => array(1,2,3) would delete objects that have ID 1,2, or 3.
     * @param bool $clearCache - optionally set to false to not have this operation clear the
     *                           cache afterwards.
     * @return array<AbstractTableRowObject>
     * @throws \Exception
     */
    protected function deleteWhereOr(array $wherePairs, $clearCache=true) : void
    {
        $query = PgsqlLib::generateDeleteWhereQuery(
            $this->getDb(),
            $this->getTableName(),
            $wherePairs,
            Conjunction::OR
        );

        $this->getDb()->query($query);

        if ($clearCache)
        {
            $this->emptyCache();
        }
    }


    /**
     * Completely empty the cache. Do this if a table is emptied etc.
     * @return void
     */
    public function emptyCache() : void
    {
        $this->m_objectCache = array();
    }


    /**
     * Delete an object in the database by UUID.
     * @param int|string $id
     * @return void
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function delete($id): void
    {
        $query = 
            "DELETE FROM {$this->getEscapedTableName()}" . 
            " WHERE " . $this->getDb()->generateQueryPairs([$this->getIdColumnName() => $id]);

        $this->getDb()->query($query);
        $this->unsetCache($id);
    }


    /**
     * Delete a row in the table by the object's identifier.
     * @param $id - the identifier of the row you wish to delete.
     * @return void
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function deleteById($id) : void
    {
        $escapedValue = $this->getDb()->escapeValue($id);
        $query = "DELETE FROM {$this->getEscapedTableName()} WHERE {$this->getEscapedIdColumnName()} = {$escapedValue}";
        $this->getDb()->query($query);
        $this->unsetCache($id);
    }


    /**
     * Delete objects by their IDs.
     *
     * @param array $ids - the identifiers of the objects we wish to delete.
     * WARNING: This does not check to make sure they existed in the database in the first place.
     *
     * @param bool $updateCache - whether to remove the objects from the cache. Default: true
     *
     * @return void
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function deleteByIds(array $ids, bool $updateCache=true) : void
    {
        $escapedIds = $this->getDb()->escapeValues($ids);
        $query = "DELETE FROM {$this->getEscapedTableName()} WHERE {$this->getEscapedIdColumnName()} IN(" . implode(", ", $escapedIds) . ")";
        $this->getDb()->query($query);

        if ($updateCache)
        {
            foreach ($ids as $uuid)
            {
                unset($this->m_objectCache[$uuid]);
            }
        }
    }


    public function getEscapedTableName()
    {
        return pg_escape_identifier($this->getDb()->getResource(), $this->getTableName());
    }


    /**
     * Deletes all rows from the table by running TRUNCATE.
     * @param bool $inTransaction - set to true to run a slower query that won't implicitly commit
     * @return void
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function deleteAll($inTransaction=false) : void
    {
        if ($inTransaction)
        {
            # This is much slower but can be run without inside a transaction
            $query = "DELETE FROM {$this->getEscapedTableName()}";
            $this->getDb()->query($query);
        }
        else
        {
            # This is much faster, but will cause an implicit commit.
            $query = "TRUNCATE {$this->getEscapedTableName()}";
            $this->getDb()->query($query);
        }

        $this->emptyCache();
    }


    /**
     * Get the class name of the object we are going to construct.
     */
    public abstract function getObjectClassName() : string;


    /**
     * Return an inline function that takes the $row array and will call the relevant row object's
     * constructor with it.
     * @return Callable - the callable must take the data row as its only parameter and return
     *                     the created object
     *                     e.g. $returnObj = function($row){ return new rowObject($row); }
     */
    protected function getRowObjectConstructorWrapper()
    {
        $objectClassName = $this->getObjectClassName();

        $constructor = function($row, $rowFieldTypes=null) use($objectClassName) {
            return $objectClassName::createFromDatabaseRow($row, $rowFieldTypes);
        };

        return $constructor;
    }


    /**
     * Return the database connection to the database that has this table.
     * @return PgSqlConnection
     */
    public abstract function getDb() : PgSqlConnection;


    /**
     * Get the user to specify fields that may be null in the database and thus don't have
     * to be set when creating this object.
     * @return array<string> - array of column names that may be null.
     */
    abstract public function getFieldsThatAllowNull() : array;


    /**
     * Get the user to specify fields that have default values and thus don't have
     * to be set when creating this object.
     * @return array<string> - array of column names that may be null.
     */
    abstract public function getFieldsThatHaveDefaults() : array;


    /**
     * Return the name of this table as it appears in the database.
     */
    public abstract function getTableName() : string;


    /**
     * Loads a single object of this class's type from the database using the unique row_id
     * @param string uuid - the uuid of the row in the database table.
     * @param bool useCache - optionally set to false to force a database lookup even if we have a
     *                    cached value from a previous lookup.
     * @return AbstractTableRowObject - the loaded object.
     */
    public function load(string $uuid, $useCache=true) : AbstractTableRowObject
    {
        $objects = $this->loadIds(array($uuid), $useCache);

        if (count($objects) == 0)
        {
            $msg = "There is no {$this->getObjectClassName()} with object with {$this->getIdColumnName()}: {$uuid}";
            throw new \Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException($msg);
        }

        return \Programster\CoreLibs\ArrayLib::getFirstElement($objects);
    }


    /**
     * Loads a number of objects of this class's type from the database using the provided array
     * list of IDs. If any of the objects are already in the cache, they are fetched from there.
     * NOTE: The returned array of objects is indexed by the IDs of the objects.
     * @param array uuids - the list of IDs of the objects we wish to load.
     * @param bool useCache - optionally set to false to force a database lookup even if we have a
     *                        cached value from a previous lookup.
     * @return array<AbstractTableRowObject> - list of the objects with the specified IDs indexed
     *                                         by the objects ID.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError
     */
    public function loadIds(array $uuids, $useCache=true)
    {
        $loadedObjects = array();
        $constructor = $this->getRowObjectConstructorWrapper();
        $uuidsToFetch = array();

        foreach ($uuids as $uuid)
        {
            if (!isset($this->m_objectCache[$uuid]) || !$useCache)
            {
                $uuidsToFetch[] = $uuid;
            }
            else
            {
                $loadedObjects[$uuid] = $this->m_objectCache[$uuid];
            }
        }

        if (count($uuidsToFetch) > 0)
        {
            $db = $this->getDb();

            $query = "SELECT * FROM {$this->getEscapedTableName()}" .
                     " WHERE {$this->getDb()->escapeIdentifier($this->getIdColumnName())} IN(" . implode(", ", $this->getDb()->escapeValues($uuidsToFetch)) . ")";

            /* @var $result \Pgsql\Result */
            $result = $this->getDb()->query($query);
            $fieldInfoMap = pg_meta_data($this->getDb()->getResource(), $this->getTableName());

            while (($row = pg_fetch_assoc($result)) != null)
            {
                /* @var $object AbstractUuidTableRowObject */
                $object = $constructor($row, $fieldInfoMap);
                $objectUUID = $object->getId();
                $this->m_objectCache[$objectUUID] = $object;
                $loadedObjects[$objectUUID] = $this->m_objectCache[$objectUUID];
            }
        }

        return $loadedObjects;
    }


    /**
     * Loads all of these objects from the database.
     * This also clears and fully loads the cache.
     * @param void
     * @return array
     */
    public function loadAll() : array
    {
        $this->emptyCache();
        $query = "SELECT * FROM {$this->getEscapedTableName()}";
        $result = $this->getDb()->query($query);
        return $this->convertPgResultToObjects($result);
    }


    /**
     * Loads a range of data from the table.
     * It is important to note that offset is not tied to ID in any way.
     * @param type $offset
     * @param type $numElements
     * @return array<AbstractTableRowObject>
     */
    public function loadRange(int $offset, int $numElements) : array
    {
        $query = "SELECT * FROM {$this->getEscapedTableName()} OFFSET {$offset} LIMIT {$numElements}";
        $result  = $this->getDb()->query($query);

        if ($result === FALSE)
        {
           throw new \Exception('Error selecting all objects for loading. ' . pg_result_error($result));
        }

        return $this->convertPgResultToObjects($result);
    }


    /**
     * Remove the cache entry for an object.
     * This should only happen when objects are destroyed.
     * This will not throw exception/error if id doesn't exist.
     * @param int|string $objectId - the ID of the object we wish to clear the cache of.
     */
    public function unsetCache(int|string $objectId) : void
    {
        unset($this->m_objectCache[$objectId]);
    }


    /**
     * Update a row specified by the ID with the provided data.
     * @param int $id - the UUID of the object being updated (may not necessarily be the same as $row['id'] if
     * changing the objects ID.
     * @param array $row - the data to update the object with
     * @return AbstractTableRowObject
     * @throws \Exception if query failed.
     */
    public function update(int|string $id, array $row) : AbstractTableRowObject
    {
        # This logic must not ever be changed to load the row object and then call update on that
        # because it's update method will call this method and you will end up with a loop.
        $query =
            "UPDATE {$this->getEscapedTableName()} SET " .
            PgsqlLib::generateQueryPairs($this->getDb()->getResource(), $row) .
            " WHERE {$this->getDb()->generateQueryPairs([$this->getIdColumnName() => $id])}";

        $result = $this->getDb()->query($query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to update row in " . $this->getTableName());
        }

        if (isset($this->m_objectCache[$id]))
        {
            $existingObject = $this->getCachedObject($id);
            $newArrayForm = $existingObject->getArrayForm();

            # overwrite the existing data with the new.
            foreach ($row as $columnName => $value)
            {
                $newArrayForm[$columnName] = $value;
            }

            $objectConstructor = $this->getRowObjectConstructorWrapper();
            $updatedObject = $objectConstructor($newArrayForm);
            $this->updateCache($updatedObject);
        }
        else
        {
            # We don't have the object loaded into cache so we need to fetch it from the
            # database in order to be able to return an object. This updates cache as well.
            # We also need to handle the event of the update being to change the ID.
            if (isset($row[$this->getIdColumnName()]))
            {
                $updatedObject = $this->load($row[$this->getIdColumnName()]);
            }
            else
            {
                $updatedObject = $this->load($id);
            }
        }

        # If we changed the object's ID, then we need to remove the old cached object.
        if (isset($row['id']) && $row['id'] != $id)
        {
            $this->unsetCache($id);
        }

        return $updatedObject;
    }


    /**
     * Delete rows from this table that
     * @param array $ids - a list of the only IDs we wish to keep.
     * @return void
     */
    public function deleteIdsNotIn(array $ids)
    {
        if (count($ids) > 0)
        {
            $escapedIdsString = $this->getDb()->escapeValues($ids);
            $query = "DELETE FROM {$this->getEscapedTableName()} WHERE {$this->getEscapedIdColumnName()} NOT IN (" . implode(", ", $escapedIdsString) . ")";
        }
        else
        {
            $query = "DELETE FROM {$this->getEscapedTableName()}";
        }

        $this->getDb()->query($query); // throws exception on error
    }


    /**
     * Batch update/insert a bunch of objects.
     * @param array $objects
     * @return void
     */
    public function batchSave(array $objects)
    {
        if (count($objects) > 0)
        {
            /* @var $object AbstractStringIdTableRowObject */
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

            // php 8.1 throws exception, not return false, if something goes wrong.
            $this->getDb()->query($multiQuery);

            // if we get here, there was no exception from query failing
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
        if (count($ids) > 0)
        {
            $escapedIds = $this->getDb()->escapeValues($ids);
            $idsString = implode(", ", $escapedIds);
            $result = $this->getDb()->query("SELECT * FROM {$this->getEscapedTableName()} WHERE {$this->getEscapedIdColumnName()} NOT IN ({$idsString})");
        }
        else
        {
            $result = $this->getDb()->query("SELECT * FROM {$this->getEscapedTableName()}");
        }

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


    public function getEscapedIdColumnName() : string
    {
        static $escapedName = null;

        if ($escapedName === null)
        {
            $escapedName = (string)$this->getDb()->escapeIdentifier($this->getIdColumnName());
        }

        return $escapedName;
    }
}
