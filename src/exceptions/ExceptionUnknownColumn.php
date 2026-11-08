<?php

/*
 * An exception for when given a column that isn't recognised, e.g. a key in a row or in where pairs that isn't one of
 * the table's columns, or that a record has no setter for. This is usually a typo, which would otherwise mean the
 * value is silently dropped, or the query fails with a less clear error.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects\Exceptions;


class ExceptionUnknownColumn extends AbstractException
{

}
