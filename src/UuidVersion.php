<?php

/*
 * The versions of UUID that AbstractUuidTable can generate for new rows, chosen per table with getUuidVersion().
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


/**
 * These are the versions pgsql-lib can generate. For any other version, override the table's generateId() instead
 * (the identifiers page of the docs shows how).
 */
enum UuidVersion
{
    # Starts with the time it was made, to the millisecond, then random bits, so new IDs sort after older ones, and new
    # rows are quick to insert into the index. The default, and the right choice for almost every table.
    case V7;

    # Fully random, so it gives nothing away, not even when its row was created. New rows go all over the index, so
    # inserting into a large table is slower. Use it for a table where the creation time must stay private.
    case V4;

    # Holds the time it was made, with a random node rather than the machine's MAC address. It starts with the lowest
    # bits of the time, so it doesn't sort by it, and new rows go all over the index, as with V4. Only use it for a
    # table whose IDs must be version 1, e.g. as something else expects them.
    case V1;
}
