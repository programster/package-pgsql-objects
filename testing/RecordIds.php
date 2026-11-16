<?php

/*
 * Gets a record's ID as the type its table's IDs are, for the tests that need it as one. A record's getId() can return
 * any type an ID can be, or null before the database has generated it, while a UUID table's saved record always has a
 * string, and a SERIAL table's an int.
 */

use Programster\PgsqlObjects\AbstractRecord;

final class RecordIds
{
    /**
     * Get the ID of a record that has one, e.g. as it has been saved.
     * @throws \Exception - if the record has no ID yet.
     */
    public static function get(AbstractRecord $record) : int|string
    {
        $id = $record->getId();

        if ($id === null)
        {
            throw new \Exception("The " . get_class($record) . " has no ID.");
        }

        return $id;
    }


    /**
     * Get the record's ID, which must be a string, e.g. a UUID.
     * @throws \Exception - if the record's ID isn't a string, e.g. it has none yet.
     */
    public static function getString(AbstractRecord $record) : string
    {
        $id = $record->getId();

        if (!is_string($id))
        {
            throw new \Exception("The " . get_class($record) . "'s ID is " . var_export($id, true) . ", not a string.");
        }

        return $id;
    }


    /**
     * Get the record's ID, which must be an int, e.g. from a SERIAL column.
     * @throws \Exception - if the record's ID isn't an int, e.g. it has none yet.
     */
    public static function getInt(AbstractRecord $record) : int
    {
        $id = $record->getId();

        if (!is_int($id))
        {
            throw new \Exception("The " . get_class($record) . "'s ID is " . var_export($id, true) . ", not an int.");
        }

        return $id;
    }
}
