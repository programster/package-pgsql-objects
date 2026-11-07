<?php

/*
 * An exception for when trying to save or update an object that has been deleted, which would otherwise insert its
 * row into the database again.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects\Exceptions;


class ExceptionObjectDeleted extends AbstractException
{

}
