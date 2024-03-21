<?php

/*
 * A class to represent a table in the database that uses UUIDs for its identifying primary key.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;



abstract class AbstractUuidTable extends AbstractTable
{
    public function generateId(): string
    {
        return Utils::generateUuid();
    }
}