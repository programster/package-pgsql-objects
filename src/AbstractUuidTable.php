<?php

/*
 * A class to represent a table in the database that uses UUIDs for its identifying primary key.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


use Programster\PgsqlObjects\Exceptions\ExceptionInvalidId;
use Ramsey\Uuid\Uuid;

abstract class AbstractUuidTable extends AbstractTable
{
    /**
     * Get the version of UUID that generateId() makes for this table's new rows. Version 7, the default, starts with
     * the time the row was created, which keeps inserts quick, but means anyone with an ID can tell when its row was
     * created. Override this to return UuidVersion::V4 for a table where that must stay private.
     *
     * @return UuidVersion
     */
    public function getUuidVersion() : UuidVersion
    {
        return UuidVersion::V7;
    }


    /**
     * Generate a new ID for a row: a UUID of the version getUuidVersion() returns, v7 by default.
     * @return string - the generated UUID, in lower case.
     */
    public function generateId() : string
    {
        $uuid = match ($this->getUuidVersion()) {
            UuidVersion::V7 => Uuid::uuid7(),
            UuidVersion::V4 => Uuid::uuid4(),
        };

        return $uuid->toString();
    }


    /**
     * Check that a value is a UUID, and return it in lower case, as PostgreSQL returns UUIDs, so that it matches the
     * cache and the IDs of loaded records. Every method that takes an ID calls this before any query runs, so a
     * malformed ID, e.g. from a mistyped URL, throws this rather than failing as a query error. Only the standard form
     * is accepted: 32 hex digits in groups of 8, 4, 4, 4 and 12, separated by hyphens, in either case. Every version is
     * accepted, whatever getUuidVersion() returns, so a table that already holds IDs of other versions still works.
     *
     * @param int|string $id - the ID to check.
     *
     * @return string - the UUID, in lower case.
     *
     * @throws ExceptionInvalidId - if the value isn't a UUID.
     */
    public function normalizeId(int|string $id) : string
    {
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        if (!is_string($id) || preg_match($uuidPattern, $id) !== 1)
        {
            $value = var_export($id, true);
            $tableName = $this->getTableName();
            throw new ExceptionInvalidId("{$value} isn't a UUID, so it can't be an ID of the {$tableName} table.");
        }

        return strtolower($id);
    }


    public function canCodeGenerateId() : bool
    {
        return true;
    }


    public function canDatabaseGenerateId() : bool
    {
        return false;
    }
}
