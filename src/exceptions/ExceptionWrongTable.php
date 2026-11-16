<?php

/*
 * An exception for when a table is given a record that isn't one of its own, e.g. as the record's getTableHandler()
 * returns the wrong table.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects\Exceptions;


class ExceptionWrongTable extends AbstractException
{

}
