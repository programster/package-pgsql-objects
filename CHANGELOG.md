# Changelog

All notable changes to this package are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package follows
[Semantic Versioning](https://semver.org/).


## [5.0.0] - Unreleased

5.0 is in development on the `5.x` branch, with alpha releases before the final tag. It
can still change between alphas.

### Breaking changes
Each of these needs a change to code that uses 4.x.

**Dependencies**
- Requires `programster/pgsql-lib` 3.0. This package's `getDb()` returns pgsql-lib's
  `PgSqlConnection`, so code that uses the connection directly is affected by pgsql-lib's
  own changes. In particular, `PgSqlConnection::create()` now requires the `$forceNew`
  argument, before `$port`.

**Renamed methods**

| 4.x | 5.0 |
|---|---|
| `$table->create($row)` | `$table->insert($row)` |
| `$table->update($id, $row)` | `$table->updateById($id, $row)` |
| `$table->delete($id)` | `$table->deleteById($id)` |
| `$table->deleteIds($ids)` | `$table->deleteByIds($ids)` |
| `$table->getEscapedTableName()` | `$table->getEscapedQualifiedTableName()`, which includes the schema |
| `deleteAll($inTransaction)` | `deleteAll($useTruncate)`. See below, as the meaning is reversed. |
| `load($id, $useCache)` and `loadIds($ids, $useCache)` | `load($id, $cacheMode)` and `loadIds($ids, $cacheMode)`, with a `CacheMode` instead of a bool, defaulting to `CacheMode::USE_CACHE`. Replace `false` with `CacheMode::UPDATE_CACHE` |
| `loadAll()`, `loadWhereAnd($wherePairs)`, `loadWhereOr($wherePairs)`, `loadAfterId($afterId, $numElements)` and `loadIdsNotIn($ids)` | The same, with a required `CacheMode` added as the last parameter. See below. |
| `loadRange($offset, $numElements, $orderByColumn)` | `loadRange($offset, $numElements, $cacheMode, $orderByColumn)`, with the required `CacheMode` before the optional `$orderByColumn` |
| `Constraint::create*($tableName, ...)` | `Constraint::create*($table, ...)`, and `$referencedTableName` is now `$referencedTable` |

The row object's own `delete()` and `update($data)` are unchanged.

**Removed**
- `Utils` and `Utils::generateUuid()`. Use your table's `generateId()` instead.
- The row object's `generateId()`. New objects get their ID when they are created.
- The `$updateCache` parameter of `deleteByIds()` and `deleteWhereAnd()`, and the
  `$clearCache` parameter of `deleteWhereOr()`. Deleted objects are now always removed from
  the cache, as leaving them there meant `load()` could still return them.
- `ExceptionQueryFailed`, which nothing threw. Failed queries throw pgsql-lib's
  `ExceptionQueryError`.
- `getCachedObject()`, which nothing used.
- The protected `updateCache()`. Use the new public `cacheObject()` instead.

**Table classes**
- `getVirtualFields()` no longer has a default implementation, and is now abstract like
  `getFieldsThatHaveDefaults()`, so every table has to implement it, returning an empty
  array if it has no generated columns.
- `TableInterface::generateId()` now returns `int|string`, instead of `mixed`. A table
  whose `generateId()` always throws can declare `never`.
- A class that implements `TableInterface` directly, rather than extending `AbstractTable`,
  must also implement the new `getSchemaName()`, `getEscapedQualifiedTableName()`,
  `getTable()`, `batchInsert()`, `enableCache()`, `disableCache()`, `isCacheEnabled()`,
  `emptyCache()` and `cacheObject()`.

**Behaviour**
- The object cache is now disabled by default, so every load queries the database. Code
  that relied on it, e.g. calling `loadAll()` and then `load($id)` without another query,
  still works, but runs more queries until it calls `enableCache()` on the table first.
  While the cache is enabled, it now holds one object for each row:
  - `save()` and `update()` keep the object they were called on in the cache, instead of a
    copy of it, and `updateById()` refreshes the cached object rather than replacing it.
  - Every load takes a `CacheMode`, saying how it uses the cache: `USE_CACHE` returns
    cached rows as the cached objects, as they are, and caches the rows it fetched,
    `UPDATE_CACHE` fetches every row and updates the cached objects in place, and
    `IGNORE_CACHE` fetches every row as a new object without touching the cache.
    `load()` and `loadIds()` default to `USE_CACHE`, which doesn't query the database
    for a row that's cached. The queries that return many rows (`loadAll()`,
    `loadWhereAnd()`, `loadWhereOr()`, `loadRange()`, `loadAfterId()` and
    `loadIdsNotIn()`) have no default, so that you choose each time, and every call to
    them needs updating.
  - `loadAll()` no longer empties the cache first.
  - Deleting rows through the table (`deleteById()`, `deleteByIds()`, `deleteWhereAnd()`,
    `deleteWhereOr()`, `deleteIdsNotIn()` and `deleteAll()`) marks the deleted rows' cached
    objects as deleted, so that their `save()` and `update()` throw `ExceptionObjectDeleted`
    instead of inserting the row again, and removes only those objects from the cache.
    `deleteWhereOr()` used to empty the whole cache, and `deleteWhereAnd()` now deletes in
    a single query, instead of loading the rows first.
- `deleteAll()` now runs `DELETE` by default, and `deleteAll(true)` runs `TRUNCATE`. This
  is the reverse of 4.x, where `true` meant `DELETE`. `TRUNCATE` fails on a table that
  another table's foreign key references, skips `ON DELETE` triggers, and locks the whole
  table, so it's now something you opt into.
- Every query uses the table's schema-qualified name, e.g. `"public"."user"`, so the
  connection's `search_path` is no longer used to find tables. Override `getSchemaName()`
  for a table that isn't in `public`.
- Methods that would otherwise act on every row now throw `ExceptionMissingRequiredData`
  when given nothing to filter by: `loadWhereAnd([])`, `loadWhereOr([])`,
  `deleteWhereAnd([])`, `deleteWhereOr([])` and `deleteIdsNotIn([])`. Use `loadAll()` or
  `deleteAll()` when you really do mean every row.
- An unrecognised column name now throws `ExceptionUnknownColumn`, wherever it appears:
  - in the data given to a row object's `createNewFromArray()`, `createFromDatabaseRow()`
    or `update()`, which previously ignored it (or only triggered a warning, in `update()`);
  - in a row or where pairs given to the table's `insert()`, `batchInsert()`,
    `updateById()` or where methods, and in `loadRange()`'s `$orderByColumn`, which
    previously failed with an error from PostgreSQL.

  As `createFromDatabaseRow()` is strict too, a custom query whose rows are turned into
  objects must select only the table's columns, and each row class needs a setter for
  every column in its table.
- After `delete()`, a row object can't be saved or updated again: `save()`, `update()`,
  `getSaveQuery()` and `markSaved()` throw `ExceptionObjectDeleted`, where `save()` used
  to quietly insert the row again. Clone the object to save it as a new row.
- `batchSave()` no longer generates IDs for objects that have none. Objects whose IDs
  the database generates (e.g. `SERIAL`) are instead inserted with a single
  `MERGE ... RETURNING` alongside the rest of the batch, and take on their generated IDs,
  column defaults and generated columns. This needs PostgreSQL 17 or newer: on an older
  server, a batch containing such objects throws `ExceptionUnsupportedDatabaseVersion`
  without saving anything. Batches where every object has an ID work on any version.
  Deleted objects in a batch now throw `ExceptionObjectDeleted` before anything is saved.
- `createFromDatabaseRow()` throws `ExceptionMissingRequiredData` for a row with no ID,
  instead of making up an ID for a row that doesn't exist.
- The package's own exceptions now extend its new `AbstractException`, and the checks
  above throw the package's `ExceptionMissingRequiredData` rather than pgsql-lib's
  `ExceptionNoData`.

### Added
- `batchInsert($rows)`, which inserts many rows in a single query and returns the objects
  for them, including IDs generated by the database, column defaults and generated
  columns. Rows can leave out the ID and any column that has a default or allows null.
  The returned objects aren't necessarily in the same order as the rows.
- A row object's ID can be changed through its `update()`, e.g.
  `$user->update(['id' => $newId])`, which changes its row's ID and moves it to its new
  ID in the cache.
- Support for tables in any schema, including names that contain dots or quotes. A table
  class sets its schema with `getSchemaName()`.
- `TableInterface::getSchemaName()`, `getEscapedQualifiedTableName()`, `getTable()` (which
  returns a pgsql-lib `Table`) and `batchInsert()`.
- `Constraint`'s factory methods accept a pgsql-lib `Table` as well as a table name, so
  constraints can be created on a table in a specific schema, and a foreign key can
  reference a table in another schema. `Constraint::getTable()` returns the table.
- `AbstractException`, which every exception this package throws extends, so they can all
  be caught together. pgsql-lib's exceptions, such as `ExceptionQueryError`, still extend
  pgsql-lib's own `AbstractException`.
- `ExceptionObjectDeleted`, `ExceptionTableNotFound` and `ExceptionUnknownColumn`.
- The `CacheMode` enum, with `USE_CACHE`, `UPDATE_CACHE` and `IGNORE_CACHE`, which every
  load takes to say how it uses the cache.
- `enableCache()`, `disableCache()` and `isCacheEnabled()`, to switch a table's cache on
  and off, and `cacheObject()`, which row objects use to keep themselves in the cache
  after saving.
- The row object's `isSavedInDatabase()` and `isDeleted()`.
- `ExceptionUnsupportedDatabaseVersion`, for something that needs a newer version of
  PostgreSQL than the server's.

### Changed
- A table's column info, used to convert loaded values to PHP types, is fetched from the
  database once per table class and then reused, instead of on every load, insert and
  update. This halves the queries those operations need.
- The column info is read from PostgreSQL's system catalogs, instead of `pg_meta_data()`,
  which looked up unqualified table names in the `public` schema, and couldn't find a
  table in a schema whose name contains a dot.
- New objects only get an ID generated when they are created (`createNewFromArray()`,
  `insert()`, `batchInsert()` and cloning).
- `delete()` on a row object that has no ID yet, because the database generates it and it
  hasn't been saved, does nothing, instead of failing with a `TypeError`.
- Calling `delete()` again on a deleted object does nothing.
- A row object's `update()` always saves the object: on an object that has never been
  saved, e.g. a new one from `createNewFromArray()`, it inserts the row, as `save()` does.
  It used to try to update a row that didn't exist, throwing `ExceptionNoSuchIdException`,
  or a `TypeError` if the database generates the table's IDs.
- The test suite's development dependencies match pgsql-lib 3.0's: `programster/autoloader`
  replaces `irap/autoloader`, and `programster/pgsql-migrations` is `^1.1` instead of
  `>=0.3 <2.0`. Applications using this package aren't affected.
- The tests run on PostgreSQL 14 to 18, not just 18. `testing/run-all-versions.sh` runs
  them against each version in a throwaway docker container, and the test connection
  details can be set with `TEST_`-prefixed environment variables, e.g. `TEST_DB_PORT`. On
  versions older than 17, the tests check that `batchSave()` rejects new objects without
  IDs with `ExceptionUnsupportedDatabaseVersion`, saving nothing.
- The tests run on GitHub Actions, on Linux (PHP 8.2 to 8.5), macOS, Windows and WSL,
  against PostgreSQL 14 to 18 (18 in WSL), for both the `main` and `5.x` branches, with
  a badge for each platform and branch at the top of the README.

### Fixed
- `deleteIdsNotIn()` left the deleted objects in the cache, so `load()` could still
  return them.
