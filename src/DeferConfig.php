<?php

/*
 * How a constraint can be deferred until the end of a transaction.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;

enum DeferConfig: string
{
    case INITIALLY_DEFERRED = "DEFERRABLE INITIALLY DEFERRED";
    case INITIALLY_IMMEDIATE = "DEFERRABLE INITIALLY IMMEDIATE";
    case NOT_DEFERRABLE = "NOT DEFERRABLE";
}
