# Reference

Every public method, in one place, with a link to where it's explained. 
`$cacheMode` is always a `CacheMode` (see [The cache](cache.md)).

- [Table classes](#table-classes)
- [Row classes](#row-classes)
- [Transaction](#transaction)
- [Constraint](#constraint)
- [Enums](#enums)


## Table classes

`AbstractTable`, and `AbstractUuidTable`, which extends it. Get a table with 
`YourTable::getInstance()`.

### Loading ([Loading](loading.md))

| Method | Does |
|---|---|
| `load($id, $cacheMode = USE_CACHE)` | Loads one object by ID, or throws `ExceptionNoSuchIdException`. |
| `loadIds($ids, $cacheMode = USE_CACHE)` | Loads the objects with these IDs, indexed by ID. |
| `loadAll($cacheMode)` | Loads every row. |
| `loadWhereAnd($wherePairs, $cacheMode)` | Loads the rows that have all of these column values. |
| `loadWhereOr($wherePairs, $cacheMode)` | Loads the rows that have any of these column values. |
| `loadIdsNotIn($ids, $cacheMode)` | Loads every row except these IDs. |
| `loadRange($offset, $numElements, $cacheMode, $orderByColumn = null)` | Loads a page by offset. |
| `loadAfterId($afterId, $numElements, $cacheMode)` | Loads the next page after an ID (keyset pagination). |

### Saving ([Saving](saving.md))

| Method | Does |
|---|---|
| `insert($row)` | Inserts a row from an array, and returns its object. |
| `batchInsert($rows)` | Inserts many rows from arrays in one query, and returns their objects. |
| `updateById($id, $row)` | Updates a row by ID from an array, and returns its object. |
| `batchSave($objects)` | Saves many objects of this table in one query. |

### Deleting ([Deleting](deleting.md))

| Method | Does |
|---|---|
| `deleteById($id)` | Deletes one row. |
| `deleteByIds($ids)` | Deletes these rows, in one query. |
| `deleteWhereAnd($wherePairs)` | Deletes the rows that have all of these column values. |
| `deleteWhereOr($wherePairs)` | Deletes the rows that have any of these column values. |
| `deleteIdsNotIn($ids)` | Deletes every row except these IDs. |
| `deleteAll($useTruncate = false)` | Deletes every row, with `DELETE`, or `TRUNCATE` if `true`. |

### The cache ([The cache](cache.md))

| Method | Does |
|---|---|
| `enableCache()` | Turns the cache on. |
| `disableCache()` | Turns the cache off, and empties it. |
| `isCacheEnabled()` | Whether the cache is on. |
| `emptyCache()` | Empties the cache, leaving it on if it was. |
| `cacheObject($object)` | Makes a saved object the cached one for its row. Row objects call this themselves after saving. |

### Describing the table ([Tables and rows](tables.md))

Your table class fills in the abstract ones.

| Method | Returns |
|---|---|
| `getDb()` | The connection. Abstract. |
| `getTableName()` | The table's plain name. Abstract. |
| `getSchemaName()` | The schema's plain name. `public` unless overridden. |
| `getObjectClassName()` | The row class. Abstract. |
| `getFieldsThatAllowNull()` | The columns that can be null. Abstract. |
| `getFieldsThatHaveDefaults()` | The columns with a default. Abstract. |
| `getVirtualFields()` | The columns the database always generates. Abstract. |
| `getFieldsToKeepAsString()` | The columns not to convert to PHP types. None unless overridden. |
| `getIdColumnName()` | The ID column's name. `id` unless overridden. |
| `generateId()` | A new ID ([IDs](ids.md)). `AbstractUuidTable` generates v7 UUIDs. |
| `canCodeGenerateId()` | Whether `generateId()` can generate IDs. |
| `canDatabaseGenerateId()` | Whether the database generates IDs. |
| `getEscapedQualifiedTableName()` | The escaped, schema-qualified name, e.g. `"public"."user"`, for your own queries. |
| `getEscapedIdColumnName()` | The escaped ID column name. |
| `getTable()` | The table as a pgsql-lib `Table`, with its schema. |

### For your own table methods ([Writing your own queries](custom-queries.md))

These are protected, so they can only be used from within the table class.

| Method | Does |
|---|---|
| `convertPgResultToObjects($result, $cacheMode)` | Turns a query's result into objects. |
| `getFieldInfoMap()` | The table's column info, as used to convert values to PHP types. |
| `getRowHydrator()` | A closure that creates an object from a row. |
| `removeVirtualFields($row)` | Removes the columns the database generates from a row. |


## Row classes

`AbstractTableRowObject`.

| Method | Does |
|---|---|
| `YourRecord::createNewFromArray($data)` | Creates a new, unsaved object, generating its ID if the table can ([Saving](saving.md)). |
| `YourRecord::createFromDatabaseRow($row, $fieldInfoMap = null)` | Creates the object for a row that's already in the database. |
| `save()` | Saves the object: updates its row, or inserts it if it's new. |
| `update($data)` | Changes some of its values, and saves straight away. |
| `delete()` | Deletes its row, and marks it as deleted ([Deleting](deleting.md)). |
| `getId()` | Its ID, or `null` if the database will generate it and it hasn't been saved. |
| `getArrayForm()` | Its values, keyed by column name. |
| `isSavedInDatabase()` | Whether it has been saved. |
| `isDeleted()` | Whether it has been deleted. |
| `clone $object` | A new, unsaved copy with a new ID. |

Your row class fills in `getTableHandler()`, `getAccessorFunctions()` and 
`getSetFunctions()` ([Tables and rows](tables.md#a-row-class)).

`getSaveQuery()` and `markSaved()` are public for the table's use, and you 
shouldn't need them.


## Transaction

([Transactions](transactions.md))

| Method | Does |
|---|---|
| `new Transaction()` | Starts an empty transaction. |
| `save($object)` | Adds an object to save. |
| `delete($object)` | Adds an object to delete. |
| `commit()` | Makes every change, in order, in one transaction and one round trip. |


## Constraint

([Constraints](constraints.md))

| Method | Does |
|---|---|
| `Constraint::createPrimary($table, $name, $columnNames, $deferConfig, $db)` | A primary key. |
| `Constraint::createUnique($table, $name, $columnNames, $deferConfig, $db)` | A unique constraint. |
| `Constraint::createForeignKey($table, $columnName, $name, $referencedTable, $referencedColumn, $deferConfig, $db)` | A foreign key. |
| `getSqlCreate()` / `getSqlDelete()` | The SQL to add or drop it. |
| `getName()`, `getTable()`, `getTableName()` | Its name and table. |


## Enums

| Enum | Cases |
|---|---|
| `CacheMode` | `USE_CACHE`, `UPDATE_CACHE`, `IGNORE_CACHE` ([The cache](cache.md)). |
| `DeferConfig` | `NOT_DEFERRABLE`, `INITIALLY_IMMEDIATE`, `INITIALLY_DEFERRED` ([Constraints](constraints.md)). |
| `TransactionChangeType` | `SAVE`, `DELETE`. Used inside `Transaction`, so you don't need it. |
