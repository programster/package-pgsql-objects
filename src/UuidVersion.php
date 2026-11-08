<?php

/*
 * The versions of UUID that AbstractUuidTable can generate for new rows, chosen per table with getUuidVersion().
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


/**
 * Only the versions that are good for new rows' IDs are here. v6 and v1 can make new IDs too, but v7 does what they
 * do, better: the RFC (9562) recommends v7 for new systems, and v1 starts with the lowest bits of its timestamp, so it
 * doesn't sort by time, which makes it slow to insert into a large index. v3 and v5 are hashes of a name, so the same
 * name always gives the same UUID, v2 can only make a few IDs a minute, and v8 has no standard layout to generate. If
 * you really need another version, e.g. for a column restricted to v1, override the table's generateId() instead (the
 * identifiers page of the docs shows how).
 */
enum UuidVersion
{
    # Starts with the time it was made, to the millisecond, then random bits, so new IDs sort after older ones, and new
    # rows are quick to insert into the index. The default, and the right choice for almost every table.
    case V7;

    # Fully random, so it gives nothing away, not even when its row was created. New rows go all over the index, so
    # inserting into a large table is slower. Use it for a table where the creation time must stay private.
    case V4;
}
