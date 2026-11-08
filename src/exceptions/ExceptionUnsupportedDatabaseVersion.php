<?php

/*
 * An exception for when something needs a newer version of PostgreSQL than the one the connection is to, e.g.
 * batchSave() of new objects whose IDs the database generates, which needs PostgreSQL 17 or newer.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects\Exceptions;


class ExceptionUnsupportedDatabaseVersion extends AbstractException
{

}
