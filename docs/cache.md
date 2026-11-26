# The cache

Each table has an optional cache, so you don't re-fetch objects when you don't 
need to. It's **disabled by default**, so every load gets fresh data from the 
database, which is the safe choice for scripts and long-running processes. 
Enable it for a table with `enableCache()` when it helps, such as in a web 
request:

```php
// in the controller
$userTable = UserTable::getInstance();
$userTable->enableCache();
$userTable->loadAll(CacheMode::USE_CACHE);

// later, in a view or model, without a query
$author = UserTable::getInstance()->load($post->getAuthorId());
```

Call `disableCache()` to switch the cache off and empty it, or `emptyCache()` 
to empty it and leave it on. If your server keeps PHP running between requests 
(e.g. FrankenPHP's worker mode, RoadRunner or Swoole), call `disableCache()` at 
the end of each request, so the cache doesn't carry over into the next one.


## One object per row

While the cache is enabled, there is only one object for each row: anything 
that loads, inserts or saves a row uses that same object, so different parts of 
the code never hold different versions of it. Deleting a row through the table 
or a [transaction](transactions.md) marks its cached object as deleted, so its 
`save()` and `update()` throw `ExceptionObjectDeleted`, and removes it from the 
cache.

While a transaction is open on the table's connection, a row that's written or 
refreshed is removed from the cache rather than stored in it, as the change 
could still be rolled back. The next load simply reads it from the database.


## CacheMode

Every load takes a `CacheMode`, saying how it uses the cache:

- `CacheMode::USE_CACHE` returns rows that are cached as the cached objects, as 
  they are, and caches the rows it had to fetch. Loading a cached row by ID 
  doesn't query the database at all. This is the default for `load()` and 
  `loadIds()`.
- `CacheMode::UPDATE_CACHE` fetches every row from the database, and updates 
  the cached objects with the rows' current values, in place.
- `CacheMode::IGNORE_CACHE` fetches every row as a new object, and leaves the 
  cache alone.
- `CacheMode::USE_CACHE_IF_COMPLETE` acts as `USE_CACHE`, except that 
  `loadAll()` doesn't query the database at all once the cache holds every row 
  (see [Loading the whole table once](#loading-the-whole-table-once)).

With the cache disabled, every mode acts as `IGNORE_CACHE`.

Queries that return many rows, such as `loadAll()` and `loadWhereAnd()`, have 
no default, so you choose each time.

The cache is never searched by columns other than the ID: these queries always 
run, as only the database knows which rows match, and with `USE_CACHE` the 
matching rows that are cached are then returned as their cached objects. So a 
cached object with unsaved changes can be returned by a filter it no longer 
matches. Use `UPDATE_CACHE` or `IGNORE_CACHE` when you need the database's 
values.


## Loading the whole table once

A small table, such as a list of statuses for a dropdown, is often loaded in 
full by several parts of the same request. With 
`CacheMode::USE_CACHE_IF_COMPLETE`, only the first `loadAll()` queries the 
database:

```php
$statusTable = StatusTable::getInstance();
$statusTable->enableCache();

// in the controller: queries the database, and fills the cache with every row.
$statuses = $statusTable->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);

// later, in a view: returns the cached objects, without a query.
$statuses = $statusTable->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);
```

The cache holds every row once a `loadAll()` has filled it, with any mode but 
`IGNORE_CACHE`, while the cache is enabled and no transaction is open on the 
table's connection. That `loadAll()` also forgets the cached objects of any 
rows it didn't find, e.g. as another process deleted them. `isCacheComplete()` 
says whether the cache holds every row.

The cache goes on holding every row while the table's own inserts, updates, 
saves and deletes keep it up to date, including `batchInsert()`, 
`batchSave()`, `deleteWhereAnd()` and the other deletes, and changing a row's 
ID. It stops holding every row, and the next `loadAll()` queries the database 
again, when:

- the cache is emptied with `emptyCache()`, or disabled;
- a write in an open transaction removes a row from the cache, as the change 
  could still be rolled back, or a delete in an open transaction could bring 
  its row back that way;
- `batchSave()` caches an object whose row a trigger skipped, so may not exist.

The table can't see writes it doesn't make itself: rows changed with raw SQL 
through its connection, or by another process. Call `emptyCache()` after 
writing to the table any other way, and after a transaction is rolled back, 
as the cache otherwise goes on returning what it held.

A `loadAll()` given a `SortBy` always queries the database, even with 
`USE_CACHE_IF_COMPLETE`, as only the database sorts exactly as PostgreSQL 
does. Without one, the cached objects come back in no particular order, as 
`loadAll()` promises. Every other load treats `USE_CACHE_IF_COMPLETE` exactly 
as `USE_CACHE`.
