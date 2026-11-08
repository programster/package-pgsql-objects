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

**Renamed classes and methods**

| 4.x | 5.0 |
|---|---|
| `AbstractTableRowObject` | `AbstractRecord`. Change each of your record classes to `extends AbstractRecord`, and any type hints of your own. Objects of these classes are called records throughout the docs. |
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

The record's own `delete()` and `update($data)` are unchanged.

**Removed**
- `Utils` and `Utils::generateUuid()`. Use your table's `generateId()` instead.
- The record's `generateId()`. New objects get their ID when they are created.
- The `$updateCache` parameter of `deleteByIds()` and `deleteWhereAnd()`, and the
  `$clearCache` parameter of `deleteWhereOr()`. Deleted objects are now always removed from
  the cache, as leaving them there meant `load()` could still return them.
- `ExceptionQueryFailed`, which nothing threw. Failed queries throw pgsql-lib's
  `ExceptionQueryError`.
- `getCachedObject()`, which nothing used.
- The protected `updateCache()`. Use the new public `cacheObject()` instead.
- `unsetCache()`. It forgot a cached object without marking it deleted, so the next load
  made a second object for the same row. Use `emptyCache()` to empty the cache, or load
  with `CacheMode::UPDATE_CACHE` to refresh the cached objects.

**Table classes**
- `getVirtualFields()` no longer has a default implementation, and is now abstract like
  `getFieldsThatHaveDefaults()`, so every table has to implement it, returning an empty
  array if it has no generated columns.
- `TableInterface::generateId()` now returns `int|string`, instead of `mixed`. A table
  whose `generateId()` always throws can declare `never`.
- `deleteWhereAnd()` and `deleteWhereOr()` are now public, so a table that overrides
  either of them must declare its override `public` too.
- A class that implements `TableInterface` directly, rather than extending `AbstractTable`,
  must also implement the new `getSchemaName()`, `getEscapedQualifiedTableName()`,
  `getTable()`, `batchInsert()`, `enableCache()`, `disableCache()`, `isCacheEnabled()`,
  `emptyCache()` and `cacheObject()`, as well as `loadIds()`, `loadIdsNotIn()`,
  `loadWhereAnd()`, `loadWhereOr()`, `deleteByIds()`, `deleteIdsNotIn()`,
  `deleteWhereAnd()`, `deleteWhereOr()` and `batchSave()`, which `AbstractTable` already
  had.

**Behaviour**
- A new object that was never given a value for a column that allows null and has a
  default (one listed in both `getFieldsThatAllowNull()` and `getFieldsThatHaveDefaults()`)
  now gets the column's default when it's inserted, rather than `NULL`. To store `NULL`,
  give it on purpose, as a key of `createNewFromArray()` or `update()`, e.g.
  `['category' => null]`. A null set through your own public setter counts as never given.
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
  - in the data given to a record's `createNewFromArray()`, `createFromDatabaseRow()`
    or `update()`, which previously ignored it (or only triggered a warning, in `update()`);
  - in a row or where pairs given to the table's `insert()`, `batchInsert()`,
    `updateById()` or where methods, and in `loadRange()`'s `$orderByColumn`, which
    previously failed with an error from PostgreSQL.

  As `createFromDatabaseRow()` is strict too, a custom query whose rows are turned into
  objects must select only the table's columns, and each record class needs a setter for
  every column in its table.
- After `delete()`, a record can't be saved or updated again: `save()`, `update()`,
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
- A record's ID can be changed through its `update()`, e.g.
  `$user->update(['id' => $newId])`, which changes its row's ID and moves it to its new
  ID in the cache.
- Support for tables in any schema, including names that contain dots or quotes. A table
  class sets its schema with `getSchemaName()`.
- `TableInterface::getSchemaName()`, `getEscapedQualifiedTableName()`, `getTable()` (which
  returns a pgsql-lib `Table`) and `batchInsert()`.
- `TableInterface` now has `AbstractTable`'s other load and delete methods, and
  `batchSave()`: `loadIds()`, `loadIdsNotIn()`, `loadWhereAnd()`, `loadWhereOr()`,
  `deleteByIds()`, `deleteIdsNotIn()`, `deleteWhereAnd()`, `deleteWhereOr()` and
  `batchSave()`. So code that only has a `TableInterface`, such as a record's
  `getTableHandler()`, can call them.
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
  and off, and `cacheObject()`, which records use to keep themselves in the cache
  after saving.
- The record's `isSavedInDatabase()` and `isDeleted()`.
- `ExceptionUnsupportedDatabaseVersion`, for something that needs a newer version of
  PostgreSQL than the server's.
- `Transaction`, which saves and deletes objects of any of your tables together, in the
  order they were added, in one database transaction: either every change is made, or
  none are. Nothing is sent until `commit()`, which sends every change as a single query,
  so it's one round trip however many objects and tables there are. Objects are only
  marked as saved or deleted, and the cache only updated, once it has been committed, so
  a failure leaves every object as it was. It defers every constraint that was created
  `DEFERRABLE`, so those are only checked once every change has been made. Every
  object's table must extend
  `AbstractTable` and use the same connection. Misusing it, e.g. adding an object both
  to save and delete, or committing it twice, throws the new
  `ExceptionInvalidTransaction`.
- `deleteWhereAnd()` and `deleteWhereOr()` can be called from outside the table, like
  `loadWhereAnd()` and `loadWhereOr()`, instead of only by the table class itself. They
  still refuse empty where pairs, rather than deleting every row.

### Changed
- Composer installs only include `src/`, `composer.json`, the README, this changelog and
  the licence. The tests, the documentation and the GitHub workflows are still in the
  repository, but no longer downloaded into your `vendor/` folder.
- The README is a short overview, with a table of contents, and the detail is in the new
  [docs/](docs/README.md) folder: a getting started guide, pages on tables and records, IDs,
  loading, saving, deleting, transactions, the cache and writing your own queries, and a
  reference of every public method and exception. It recommends UUID IDs, and
  deferrable foreign keys, so the order of a transaction's changes doesn't matter, with
  how to make new and existing constraints deferrable, including with `Constraint`.
- A table's column info, used to convert loaded values to PHP types, is fetched from the
  database once per table class and then reused, instead of on every load, insert and
  update. This halves the queries those operations need.
- The column info is read from PostgreSQL's system catalogs, instead of `pg_meta_data()`,
  which looked up unqualified table names in the `public` schema, and couldn't find a
  table in a schema whose name contains a dot.
- New objects only get an ID generated when they are created (`createNewFromArray()`,
  `insert()`, `batchInsert()` and cloning).
- `delete()` on a record that has no ID yet, because the database generates it and it
  hasn't been saved, does nothing, instead of failing with a `TypeError`.
- Calling `delete()` again on a deleted object does nothing.
- A record's `update()` always saves the object: on an object that has never been
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
  against PostgreSQL 14 to 18 (16 in WSL), with a badge for each platform at the top of
  the README.

### Fixed
- `deleteIdsNotIn()` left the deleted objects in the cache, so `load()` could still
  return them.
- Saving a new object sent `NULL` for a `NOT NULL` column with a default that it had no
  value for, instead of letting the database fill in the default, so the insert failed.
  Record classes no longer need to leave such columns out of `getAccessorFunctions()`
  while they're null. This covers `save()`, `update()`, `batchSave()` and `Transaction`,
  and each object decides for itself, so objects saved together can each give a value
  or leave it to the default.
- `batchSave()` didn't give objects that already had an ID (e.g. a UUID) the values the
  database filled in, such as column defaults and generated columns, so they kept the
  nulls they were saved with. A later `update()` then sent those nulls, failing for a
  `NOT NULL` column, or overwriting the stored value with `NULL`. Every insert and update
  in `batchSave()` and `Transaction` now returns its row, which its object takes on, as
  with `save()`, all still in one query and one round trip.
