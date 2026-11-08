<?php

/*
 * The kinds of change a Transaction makes to a record. Transaction uses this internally: add changes with its
 * save() and delete().
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


enum TransactionChangeType
{
    # Save the object: update its row if it has been saved before, or insert it if it hasn't.
    case SAVE;

    # Delete the object's row.
    case DELETE;
}
