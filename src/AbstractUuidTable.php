<?php

/*
 * A class to represent a table in the database that uses UUIDs for its identifying primary key.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


use Ramsey\Uuid\Uuid;

abstract class AbstractUuidTable extends AbstractTable
{
    /**
     * Generate a new ID for a row: a v7 UUID, which is time-ordered (sequential) for database index performance.
     * @return string - the generated UUID string.
     */
    public function generateId() : string
    {
        return Uuid::uuid7()->toString();
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
