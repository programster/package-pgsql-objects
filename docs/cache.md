# The cache

Each table has an optional cache, so you don't re-fetch objects when you don't 
need to. It's **disabled by default**, so every load gets fresh data from the 
database, which is the safe choice for scripts and long-running processes. 
Enable it for a table with `enableCache()` when it helps, such as in a web 
request:

```php
// in the controller
$users = UserTable::getInstance();
$users->enableCache();
$users->loadAll(CacheMode::USE_CACHE);

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

With the cache disabled, every mode acts as `IGNORE_CACHE`.

Queries that return many rows, such as `loadAll()` and `loadWhereAnd()`, have 
no default, so you choose each time.

The cache is never searched by columns other than the ID: these queries always 
run, as only the database knows which rows match, and with `USE_CACHE` the 
matching rows that are cached are then returned as their cached objects. So a 
cached object with unsaved changes can be returned by a filter it no longer 
matches. Use `UPDATE_CACHE` or `IGNORE_CACHE` when you need the database's 
values.
