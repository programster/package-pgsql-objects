<?php

/*
 * The exception that every exception this package throws extends, so that they can all be caught together.
 * Exceptions from pgsql-lib, such as ExceptionQueryError when a query fails, pass through unchanged and extend
 * pgsql-lib's own AbstractException instead.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects\Exceptions;


abstract class AbstractException extends \Exception
{

}
