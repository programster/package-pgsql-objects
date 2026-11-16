<?php

/*
 * An exception for when an insert didn't insert a row, e.g. as a BEFORE INSERT trigger skipped it, so there is no row
 * to build the object from.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects\Exceptions;


class ExceptionRowNotInserted extends AbstractException
{

}
