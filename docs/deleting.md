# Deleting

Rows can be deleted through the object, or through the table.

```php
// an object you already have.
$user->delete();

$userTable = UserTable::getInstance();

// by ID, or many IDs, in one query.
$userTable->deleteById($id);
$userTable->deleteByIds([$id1, $id2, $id3]);

// by filter: rows that have all of the values, or any of them.
$userTable->deleteWhereAnd(['role' => 'guest', 'active' => false]);
$userTable->deleteWhereOr(['id' => [$id1, $id2], 'role' => 'guest']);

// every row except the ones to keep.
$userTable->deleteIdsNotIn([$id1, $id2]);

// every row.
$userTable->deleteAll();
```

To delete objects of several tables together, along with saving others, use a 
[Transaction](transactions.md).


## Deleted objects

Once an object has been deleted, it's marked as deleted, and its `save()` and 
`update()` throw `ExceptionObjectDeleted`. Without that, saving an object whose 
row had been deleted would quietly insert the row again. Its values can still 
be read, and `isDeleted()` tells you whether it has been deleted.

To create the row again on purpose, clone the object and save the clone: the 
clone isn't deleted, and gets a new ID.

Deleting through the table marks the deleted rows' records as deleted too, but 
only the ones in the [cache](cache.md), as the table has no way to find any 
others. So if the cache is off, prefer deleting an object you're holding 
through its own `delete()`, or a [Transaction](transactions.md), which marks 
the objects you give it.

`delete()` on an object that was never saved, whose ID the database would 
generate, has no row to delete, so it doesn't query the database, but it still 
marks the object as deleted. Calling `delete()` again does nothing.


## Safety checks

The deletes that take a list or a filter refuse to delete every row by 
accident:

- `deleteWhereAnd([])`, `deleteWhereOr([])` and `deleteIdsNotIn([])` throw 
  `ExceptionMissingRequiredData`, rather than deleting every row. Use 
  `deleteAll()` when that's what you mean.
- `deleteByIds([])` deletes nothing, and doesn't query the database.
- A filter column that isn't one of the table's columns throws 
  `ExceptionUnknownColumn` before any query runs.
- Deleting an ID that has no row doesn't throw.


## deleteAll()

`deleteAll()` runs `DELETE` by default. `deleteAll(true)` runs `TRUNCATE` 
instead, which is much faster on a large table, but:

- it fails if any other table has a foreign key referencing this one, even if 
  no rows reference it,
- it doesn't fire `ON DELETE` triggers,
- and it locks the whole table, blocking all other reads and writes on it 
  until the transaction ends.

Either way, it can be rolled back if run inside a transaction. Every cached 
object is marked as deleted, and the cache is emptied.
