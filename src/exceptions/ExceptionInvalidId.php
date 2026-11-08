<?php

/*
 * An exception for an ID that can't be one of the table's IDs, e.g. a string that isn't a UUID, for a table whose IDs
 * are UUIDs. It's thrown before any query runs, so an application can tell a malformed ID (e.g. a mistyped URL) from
 * one that's well formed, but has no row (ExceptionNoSuchIdException).
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects\Exceptions;


class ExceptionInvalidId extends AbstractException
{

}
