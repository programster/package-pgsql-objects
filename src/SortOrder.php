<?php

/*
 * The direction to sort rows in (see SortBy). Each case's value is its SQL keyword.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;

enum SortOrder : string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}
