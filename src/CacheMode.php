<?php

/*
 * How a load uses a table's object cache, while the cache is enabled (see AbstractTable::enableCache()). While the
 * cache is disabled, every mode behaves like IGNORE_CACHE, as there is nothing to use or update.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;

enum CacheMode
{
    /*
     * Use the cache. Rows that are cached are returned as the cached objects, as they are, without being overwritten.
     * When loading by ID, they aren't fetched from the database at all. Rows that aren't cached are fetched from the
     * database and added to the cache. The cache is never searched by other columns: queries such as loadWhereAnd()
     * always run, as only the database knows which rows match, and only then are cached rows given their cached
     * objects, which may no longer match the filter if they have unsaved changes.
     */
    case USE_CACHE;

    /*
     * Don't use the cache, but update it with what is fetched. Every row is fetched from the database. Rows that are
     * already cached have their cached object updated with the fetched values, in place, so that everything holding
     * the object sees them, and any unsaved changes to it are discarded. Rows that aren't cached are added to it.
     */
    case UPDATE_CACHE;

    /*
     * Don't use the cache, or update it. Every row is fetched from the database as a new object, and the cache isn't
     * touched, even for rows that are cached.
     */
    case IGNORE_CACHE;
}
