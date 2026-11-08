<?php

/*
 * An exception for a Transaction being used in a way it can't be, e.g. adding an object to both save and delete, adding
 * objects whose tables use different database connections, or committing it twice.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects\Exceptions;


class ExceptionInvalidTransaction extends AbstractException
{

}
