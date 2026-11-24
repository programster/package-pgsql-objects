<?php

/*
 * A class to represent a table in the database that uses UUIDs for its identifying primary key.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\UuidVersion as PgsqlLibUuidVersion;
use Programster\PgsqlObjects\Exceptions\ExceptionInvalidId;

/**
 * @template T of \Programster\PgsqlObjects\AbstractRecord
 * @extends AbstractTable<T>
 */
abstract class AbstractUuidTable extends AbstractTable
{
    /**
     * Get the version of UUID that generateId() makes for this table's new rows. Version 7, the default, starts with
     * the time the row was created, which keeps inserts quick, but means anyone with an ID can tell when its row was
     * created. Override this to return UuidVersion::V4 for a table where that must stay private, or UuidVersion::V1 for
     * one whose IDs must be version 1.
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
        $version = match ($this->getUuidVersion()) {
            UuidVersion::V7 => PgsqlLibUuidVersion::V7,
            UuidVersion::V4 => PgsqlLibUuidVersion::V4,
            UuidVersion::V1 => PgsqlLibUuidVersion::V1,
        };

        return PgsqlLib::generateUuid($version);
    }


    /**
     * Check that a value is a UUID, and return it in lower case, as PostgreSQL returns UUIDs, so that it matches the
     * cache and the IDs of loaded records. Every method that takes an ID calls this before any query runs, so a
     * malformed ID, e.g. from a mistyped URL, throws this rather than failing as a query error. Only the standard form
     * is accepted: 32 hex digits in groups of 8, 4, 4, 4 and 12, separated by hyphens, in either case, with nothing
     * before or after them, not even a trailing newline. Every version is accepted, whatever getUuidVersion() returns,
     * so a table that already holds IDs of other versions still works.
     *
     * @param int|string $id - the ID to check.
     *
     * @return string - the UUID, in lower case.
     *
     * @throws ExceptionInvalidId - if the value isn't a UUID.
     */
    public function normalizeId(int|string $id) : string
    {
        if (!is_string($id) || !PgsqlLib::isValidUuid($id))
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
