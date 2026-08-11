<?php

/*
 * A class to represent a table in the database that uses UUIDs for its identifying primary key.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;

use Programster\PgsqlLib\Exceptions\ExceptionUnexpectedValueType;

abstract class AbstractUuidTable extends AbstractTable
{
    public function generateId(): string
    {
        return Utils::generateUuid();
    }


    public function isIdGeneratedInDatabase() : bool
    {
        return false;
    }


    protected function getCachedObject(string|int $id)
    {
        if (is_int($id))
        {
            throw new ExceptionUnexpectedValueType($id, 'Expecting identifier to be a string.');
        }

        parent::getCachedObject($id);
    }
}
