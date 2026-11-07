<?php

/*
 * An exception for when a table class refers to a table that can't be found in the database, e.g. because its
 * getTableName() or getSchemaName() is wrong, or the table hasn't been created yet.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects\Exceptions;


class ExceptionTableNotFound extends AbstractException
{

}
